if (typeof (console) == 'undefined') {console = {log: function(msg) {}};}
/**
   params = {
              url   : {
                        ws      : '',
                        comet   : '',
                        polling  : ''
                      }
            }
**/


WebSocketConnection = function(params){

    var self = this;
    /**
     * Путь к js и swf файлам
     */
    this.root = params.root || '/js/';
    


    /**
     * WebSocket соединение
     */
    var WS = null;



    /**
     * Статус WebSocket соединения
     */
    this.readyState     = 0;
    this.bufferedAmount = 0;
    this.readyCallbacks = [];



    /**
     * Закрытие соединения с сервером
     */
    this.close = function(){if(WS)WS.close();};
    
    this.ready = function(c)
    {
     if (self.readyState == 1) {c();}
     else
     {
      self.readyCallbacks.push(c);
     }
    };



    /**
     * Отправка данных на сервер
     */
    this.send = function(data){if(WS)WS.send(data);};
    


    /**** События  ****/

    // получен новый пакет данных
    var onmessageEvent = function(e){if(self.onmessage)self.onmessage(e);};

    // соединение с сервером установлено
    var onopenEvent = function()
    {
     self.readyState = 1;
     if(self.onopen)self.onopen();
     var c;
     while (c = self.readyCallbacks.pop()) {c();}
    };

    // соединение с сервером закрыто
    var oncloseEvent = function()
    {
     self.readyState = 3;
     if(self.onclose)self.onclose();
    };






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

          if(type == 'flash'){   // загружаем флешовый эмулятор

             WebSocket__swfLocation = self.root + 'flash.swf';  // путь к swf файлу

             if(!("FABridge" in window))loadFile('fabridge.js');   // если не загружен FABridge, загружаем

             if(!("swfobject" in window))loadFile('swfobject.js'); // если не загружен swfobject, загружаем

             var interval1 = setInterval(function(){
                 if(("FABridge" in window) && ("swfobject" in window)){
                    clearInterval(interval1);
                    loadFile('websocket_flash.js');
                 }
             },10);

          }else if(type == 'comet'){    // загружаем comet эмулятор

              loadFile('websocket_comet.js');

          }else if(type == 'polling'){   // загружаем long-poling эмулятор

              loadFile('websocket_polling.js');

          }else{
            alert('Error loadEmulator');
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

             case 'polling' : loaded = PollingDriver();
             break;
           }

           if(!loaded){
             onError();
           }
    };




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

                if (navigator.plugins["Shockwave Flash 9.0"] || navigator.plugins["Shockwave Flash 10.0"] || navigator.plugins["Shockwave Flash 10.1"]){
                    flashinstalled = 2;
                }

            }else if (navigator.mimeTypes && navigator.mimeTypes.length){

                x = navigator.mimeTypes['application/x-shockwave-flash'];
                if (x && x.enabledPlugin)
                    flashinstalled = 2;
                else
                    flashinstalled = 1;
            }else{           	
            	MSDetect = true;
            }
                
            console.log(navigator.plugins);
            console.log(navigator.mimeTypes);
         
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
                   win.document.writeln("<html><body>");
                   win.document.writeln('<script LANGUAGE="VBScript">');
                   win.document.writeln('on error resume next');
                   win.document.writeln('For i = 1 to 11');
                   win.document.writeln('If Not(IsObject(CreateObject("ShockwaveFlash.ShockwaveFlash." & i))) Then');
                   win.document.writeln('Else');
                   win.document.writeln('flashinstalled2 = 2');
                   win.document.writeln('End If');
                   win.document.writeln('Next');
                   win.document.writeln('If flashinstalled2 = 0 Then');
                   win.document.writeln('flashinstalled2 = 1');
                   win.document.writeln('End If');
                   win.document.writeln('</script>');
                   win.document.writeln('<script type="text/javascript">');
                   win.document.writeln('flashinstalled = flashinstalled2');
                   win.document.writeln('</script>');
                   win.document.writeln("</body></html>");
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
    };





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
    };




    /**
     * Проверка на доступность драйвера long-polling
     */
     var PollingDriver = function(){

         if("WebSocket" in window && ("WebSocketServicePrivider" in window) && WebSocketServicePrivider == 'polling'){
        	  createSocket(params.url.polling);
         }else{
           loadEmulator('polling', function(){
              createSocket(params.url.polling);
           });
         }
         return true;
    };





    /**
     * Иннициализируем вебсокет
     */
    var createSocket = function(url){
              WS           = new WebSocket(url);
              WS.onopen    = onopenEvent;
              WS.onmessage = onmessageEvent;
              WS.onclose   = oncloseEvent;
    };






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


