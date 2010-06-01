/**
   params = {
              url   : {
                        ws      : '',
                        comet   : '',
                        poling  : ''
                      }
            }
**/


WebSocketConnection = function(params){

    this.root = '/websocket/js/';
    
    
    var WS = null;

    var self = this;
    this.readyState     = 3;
    this.bufferedAmount = 0;

    
    this.close = function(){if(WS)WS.close();};
    this.send = function(data){if(WS)WS.send(data);};
    
    
    this.onmessageEvent = function(e){if(self.onmessage)self.onmessage(e);};
    this.onopenEvent = function(){if(self.onopen)self.onopen();};
    this.oncloseEvent = function(){if(self.onclose)self.onclose();};






    /**
     * Загрузка файла
     */
    var loadFile = function(file){
           var script = document.createElement('script');
               script.type = 'text/javascript';
               script.src = self.root + file;
           var c = document.head || document.body;
           c.appendChild(script);
    };






    /**
     * Загрузка драйвера
     */
    var loadEmulator = function(type, onLoaded){

          if(type == 'flash'){
             WebSocket__swfLocation = self.root + 'flash.swf';
             if(!("FABridge" in window))loadFile('fabridge.js');
             if(!("swfobject" in window))loadFile('swfobject.js');
             var interval1 = setInterval(function(){
                 if(("FABridge" in window) && ("swfobject" in window)){
                    clearInterval(interval1);
                    loadFile('websocket_flash.js');
                 }
             },10);

          }else if(type == 'comet'){
              loadFile('websocket_comet.js');
          }else if(type == 'poling'){
              loadFile('websocket_poling.js');
          }else{
            return;
          }

           var interval2 = setInterval(function(){
                  if("WebSocket" in window){
                       clearInterval(interval2);
                       onLoaded();
                  }
           }, 5);
    };





    /**
     * Проверка на доступность драйверов
     */
    var loadDriver = function(driver, onError){

           if(!driver)onError();
           var loaded = false;
           switch(driver){

             case 'ws'     : loaded = WSDriver();
             break;

             case 'comet'  : loaded = CometDriver();
             break;

             case 'poling' : loaded = PolingDriver();
             break;
           }

           if(!loaded){
             onError();
           }
    }




    /**
     * Проверка на доступность нативного WebSocket или Flash плеера
     */
    var WSDriver = function(){

        if("WebSocket" in window){
          createSocket(params.url.ws);
          return true;
         }

          var x, flashinstalled = 0;
               var MSDetect = false;

            if (navigator.plugins && navigator.plugins.length){
                x = navigator.plugins["Shockwave Flash"];
                if(x){
                    flashinstalled = 2;
                }else{
                    flashinstalled = 1;
                }

                if (navigator.plugins["Shockwave Flash 9.0"] || navigator.plugins["Shockwave Flash 10.0"]){
                    flashinstalled = 2;
                }

            }else if (navigator.mimeTypes && navigator.mimeTypes.length){

                x = navigator.mimeTypes['application/x-shockwave-flash'];
                if (x && x.enabledPlugin)
                    flashinstalled = 2;
                else
                    flashinstalled = 1;
            }else
                MSDetect = true;


            if(flashinstalled != 2 && MSDetect == true){
               var iframe = document.createElement('iframe');
                 with (iframe.style) {
                        left       = top   = "-100px";
                        height     = width = "1px";
                        position   = 'absolute';
                        display    = 'none';
                      }
                 document.body.appendChild(iframe);
               var win;
                 if(typeof(iframe.contentWindow) != 'undefined'){
                    win = iframe.contentWindow.window;
                 }else if(iframe.window){
                   win = iframe.window;
                 }

                 if(win){
                   win.flashinstalled = 0;
                   win.document.write("<html><body>");
                   win.document.write('<script LANGUAGE="VBScript">');
                   win.document.write('on error resume next');
                   win.document.write('For i = 1 to 11');
                   win.document.write('If Not(IsObject(CreateObject("ShockwaveFlash.ShockwaveFlash." & i))) Then');
                   win.document.write('Else');
                   win.document.write('flashinstalled = 2');
                   win.document.write('End If');
                   win.document.write('Next');
                   win.document.write('If flashinstalled = 0 Then');
                   win.document.write('flashinstalled = 1');
                   win.document.write('End If');
                   win.document.write('End If');
                   win.document.write('</script>');
                   win.document.write("</body></html>");
                   flashinstalled = win.flashinstalled;
                 }
            }

              if(flashinstalled == 2){
               loadEmulator('flash', function(){
                  createSocket(params.url.ws);
               });
               return true;
              }
         return false;
    }





    /**
     * Проверка на доступность драйвера comet
     */
    var CometDriver = function(){

       if("WebSocket" in window && ("WebSocketServicePrivider" in window) && WebSocketServicePrivider == 'comet'){
        	  createSocket(params.url.comet);
         }else{
           loadEmulator('comet', function(){
              createSocket(params.url.comet);
           });
         }

         return true;
    }




    /**
     * Проверка на доступность драйвера long-poling
     */
     var PolingDriver = function(){

         if("WebSocket" in window && ("WebSocketServicePrivider" in window) && WebSocketServicePrivider == 'poling'){
        	  createSocket(params.url.poling);
         }else{
           loadEmulator('poling', function(){
              createSocket(params.url.poling);
           });
         }
         return true;
    }





    /**
     * Иннициализируем вебсокет
     */
    var createSocket = function(url){
              WS           = new WebSocket(url);
              WS.onopen    = self.onopenEvent;
              WS.onmessage = self.onmessageEvent;
              WS.onclose   = self.oncloseEvent;
    }






     var priority = [];

        var i = 0;
         for(var type in params.url){
           priority[i] = type;
           i++;
         };

    if(typeof(priority[0]) != undefined){
       loadDriver(priority[0],function(){
         if(typeof(priority[1]) != undefined){
          loadDriver(priority[1],function(){
            if(typeof(priority[2]) != undefined){
              loadDriver(priority[2],function(){
               });
             }
           });
         }
      });
    }



};


