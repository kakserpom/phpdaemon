<?php

/**
 * @package GameMonitor
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
// db.servers.ensureIndex({address:1}, {unique:true});
class GameMonitor extends AppInstance {
	public $client;
	public $db;
	public $servers;
	
	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * Uncomment and return array with your default options
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			'dbname' => 'gamemonitor',
		);
	}
	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		if ($this->isEnabled()) {
			$this->client = ValveClient::getInstance();
			$this->db = MongoClient::getInstance();
			$this->servers = $this->db->{$this->config->dbname->value . '.servers'};
		}
	}

	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		return new GameMonitorHTTPRequest($this, $upstream, $req);
	}

	public $jobMap = array();
	public function updateServer($server) {
		if (!isset($server['address'])) {
			return;
		}
		$server['address'] = trim($server['address']);
		$app = $this;
		if (isset($app->jobMap[$server['address']])) {
			//Daemon::log('already doing: '.$server['address']);
			return;
		}
		$job = new ComplexJob(function($job) use ($app, $server) {
			unset($app->jobMap[$server['address']]);
			//Daemon::log('Removed job for '.$server['address']. ' ('.sizeof($app->jobMap).')');
			$set = $job->results['info'];
			$set['address'] = $server['address'];
			$set['players'] = $job->results['players'];
			$set['latency'] = $job->results['latency'];
			$set['atime'] = time();
			if (0) Daemon::log('Updated server ('.round(memory_get_usage(true)/1024/1024, 5).'): '.$server['address']. ' latency = '.round($set['latency'] * 1000, 2).' ==== '
				 . (isset($server['atime']) ?
				  round($set['atime'] - $server['atime']) . ' secs. from last update.'
				  : ' =---= '.json_encode($server))
			);
			try {
				$app->servers->upsert(array('_id' => $server['_id']), array('$set' => $set));
			} catch (MongoException $e) {
				Daemon::uncaughtExceptionHandler($e);
				$app->servers->upsert(array('_id' => $server['_id']), array('$set' => array('atime' => time())));
			}
		});
		$app->jobMap[$server['address']] = $job;
		//Daemon::log('Added job for '.$server['address']);

		$job('info', function ($jobname, $job) use ($app, $server) {
			$app->client->requestInfo($server['address'], function ($conn, $result) use ($app, $server, $jobname, $job) {

				$job('players', function ($jobname, $job) use ($app, $server, $conn) {
					
					$conn->requestPlayers(function ($conn, $result) use ($app,$jobname, $job) {

						$job->setResult($jobname, $result);
						$conn->finish();
	
					});
				});


				$job->setResult($jobname, $result);
			});
		});

		$job('latency', function ($jobname, $job) use ($app, $server) {
							
			$app->client->ping($server['address'], function ($conn, $result) use ($app, $jobname, $job) {
	
				$job->setResult($jobname, $result);

				$conn->finish();
	
			});	
						
		});


		$job();
	}
	
	public function updateAllServers() {
		gc_collect_cycles();
		$app = $this;
		$amount = 1000 - sizeof($this->jobMap);
		Daemon::log('amount: '.$amount);
		if ($amount <= 0) {
			return;
		}
		$this->servers->find(function($cursor) use ($app, $amount) {
			if (isset($cursor->items[0]['$err'])) {
				Daemon::log(Debug::dump($cursor->items));
				return;
			}
			foreach ($cursor->items as $server) {
				$app->updateServer($server);
			}
			$cursor->destroy();
		}, array(
			'where' => array('$or' => array(
				array('atime' => array('$lte' => time() - 30), 'latency' => array('$ne' => false)),
				array('atime' => array('$lte' => time() - 120), 'latency' => false),
				array('atime' => null),
				//array('address' => 'dimon4ik.no-ip.org:27016',)

			)),
			//'fields' => '_id,atime,address',
			'limit' => - max($amount, 100),
			'sort' => array('atime' => 1),
		));
	}

	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		if ($this->isEnabled()) {
			$this->updateTimer = setTimeout(function ($timer) {
				$this->updateAllServers();
				$timer->timeout(2e6);
			}, 1);
		}
	}
	
	/**
	 * Called when worker is going to update configuration.
	 * @return void
	 */
	public function onConfigUpdated() {
		if ($this->client) {
			$this->client->config = $this->config;
			return $this->client->onConfigUpdated();
		}
	}

	/**
	 * Called when application instance is going to shutdown.
	 * @return boolean Ready to shutdown?
	 */
	public function onShutdown() {
		if ($this->client) {
			return $this->client->onShutdown();
		}
		return true;
	}
}

class GameMonitorHTTPRequest extends HTTPRequest {

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$req = $this;

		$job = $this->job = new ComplexJob(function() use ($req) { // called when job is done

			$req->wakeup(); // wake up the request immediately

		});
				
		$job('getServers', function($name, $job) use ($req) { // registering job named 'pingjob'
		
			$req->appInstance->servers->find(function ($cursor) use ($name, $job) {
				$job->setResult($name, $cursor->items);
			});
		});

		$job(); // let the fun begin

		$this->sleep(5); // setting timeout
	}
	
	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		$this->header('Content-Type: text/html');
?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>Game servers</title>
</head>
<body>
</body></html><?php
	}
	
}
