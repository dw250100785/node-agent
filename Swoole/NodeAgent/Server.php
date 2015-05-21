<?php
namespace Swoole\NodeAgent;

class Server
{
    /**
     * @var \swoole_server
     */
    protected $serv;
    protected $files;

    protected $root_path = '/tmp/';
    protected $override = false;

    protected $max_file_size = 100000000; //100M

    function onConnect($serv, $fd, $from_id)
    {
        echo "new upload client[$fd] connected.\n";
    }

    protected function message($fd, $code, $msg)
    {
        $this->serv->send($fd, json_encode(array('code' => $code, 'msg' => $msg)));
        echo "[-->$fd]\t$code\t$msg\n";
        if ($code != 0) {
            $this->serv->close($fd);
        }
        return true;
    }

    function onReceive(\swoole_server $serv, $fd, $from_id, $data)
    {
        //传输尚未开始
        if (empty($this->files[$fd])) {
            $req = json_decode($data, true);
            if ($req === false) {
                return $this->message($fd, 400, 'Error Request');
            } elseif (empty($req['size']) or empty($req['name'])) {
                return $this->message($fd, 500, 'require file name and size.');
            } elseif ($req['size'] > $this->max_file_size) {
                return $this->message($fd, 501, 'over the max_file_size. ' . $this->max_file_size);
            }
            $file = $this->root_path . '/' . $req['name'];
            $dir = realpath(dirname($file));
            if (!$dir or strncmp($dir, $this->root_path, strlen($this->root_path)) != 0) {
                return $this->message($fd, 502, "file path[$dir] error. Access deny.");
            } elseif ($this->override and is_file($file)) {
                return $this->message($fd, 503, 'file exists. Server not allowed override');
            }
            $fp = fopen($file, 'w');
            if (!$fp) {
                return $this->message($fd, 504, 'can open file.');
            } else {
                $this->message($fd, 0, 'transmission start');
                $this->files[$fd] = array('fp' => $fp, 'name' => $file, 'size' => $req['size'], 'recv' => 0);
            }
        } //传输已建立
        else {
            $info = & $this->files[$fd];
            $fp = $info['fp'];
            $file = $info['name'];
            if (!fwrite($fp, $data)) {
                $this->message($fd, 600, "fwrite failed. transmission stop.");
                unlink($file);
            } else {
                $info['recv'] += strlen($data);
                if ($info['recv'] >= $info['size']) {
                    $this->message($fd, 0, "Success, transmission finish. Close connection.");
                    unset($this->files[$fd]);
                }
            }
        }
        return true;
    }

    function setRootPath($path)
    {
        if (!is_dir($path))
        {
            throw new Exception(__METHOD__.": $path is not exists.");
        }
        $this->root_path = $path;
    }

    function setMaxSize($max_file_size)
    {
        $this->max_file_size = (int)$max_file_size;
        if ($this->max_file_size <= 0)
        {
            throw new \Exception(__METHOD__.": max_file_size is zero.");
        }
    }

    function onclose($serv, $fd, $from_id)
    {
        unset($this->files[$fd]);
        echo "upload client[$fd] closed.\n";
    }

    function run()
    {
        $serv = new \swoole_server("0.0.0.0", 9507, SWOOLE_BASE);
        $runtime_config = array(
            'worker_num' => 1,
        );

        global $argv;
        if (!empty($argv[1]) and $argv[1] == 'daemon')
        {
            $runtime_config['daemonize'] = true;
        }
        $serv->set($runtime_config);
        $serv->on('Start', function ($serv) {
                echo "Swoole Upload Server running\n";
            });
        $this->root_path = rtrim($this->root_path, ' /');
        $serv->on('connect', array($this, 'onConnect'));
        $serv->on('receive', array($this, 'onreceive'));
        $serv->on('close', array($this, 'onclose'));
        $this->serv = $serv;
        $serv->start();
    }
}
