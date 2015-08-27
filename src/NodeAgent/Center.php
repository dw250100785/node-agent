<?php
namespace NodeAgent;

/**
 * 中心服务器，中心服务器本身也是一个节点
 * @package NodeAgent
 */
class Center extends Server
{
    /**
     * @var \redis
     */
    protected $redis;
    protected $nodes = array();

    /**
     * 600秒强制更新
     * @var int
     */
    protected $nodeInfoLifeTime = 600;

    /**
     * IP到NodeInfo的映射
     * @var array
     */
    protected $ipMap = array();

    function init()
    {
        $this->redis = \Swoole::$php->redis;
        $nodeSet = $this->redis->sMembers('nodes');
        $this->nodes = array_flip($nodeSet);
        //监听UDP端口，接受来自于节点的上报
        $this->serv->addlistener('0.0.0.0', self::PORT_UDP, SWOOLE_SOCK_UDP);
        NodeInfo::$serv = $this->serv;
    }

    function onPacket($serv, $data, $addr)
    {
        $req = unserialize($data);
        //错误的请求
        if (empty($req['cmd']))
        {
            return;
        }

        $ipAddress = $addr['address'];
        //没有建立映射
        if (empty($this->ipMap[$ipAddress]))
        {
            //建立映射
            if ($req['cmd'] == 'putInfo' and !empty($req['info']))
            {
                $nodeInfo = new NodeInfo();
                $nodeInfo->setInfo($req['info']);
                $nodeInfo->address = $ipAddress;
                $nodeInfo->port = $addr['port'];
                $this->ipMap[$ipAddress] = $nodeInfo;
            }
            else
            {
                $this->serv->sendto($addr['address'], $addr['port'], serialize([
                    'cmd' => 'getInfo',
                ]));
            }
        }
        else
        {
            $nodeInfo = $this->ipMap[$ipAddress];
            call_user_func([$this, '_udp_' . $req['cmd']], $nodeInfo, $req);
        }
    }

    /**
     * 心跳
     * @param NodeInfo $nodeInfo
     * @param array $req
     */
    protected function _udp_heartbeat($nodeInfo, $req)
    {
        //更新心跳时间
        $nodeInfo->hearbeatTime = time();
        //信息过期了需要更新
        if ($nodeInfo->updateTime < $nodeInfo->hearbeatTime - $this->nodeInfoLifeTime)
        {
            $nodeInfo->send(['cmd' => 'getInfo']);
        }
    }

    /**
     * 心跳
     * @param NodeInfo $nodeInfo
     * @param array $req
     */
    protected function _udp_putInfo($nodeInfo, $req)
    {
        if (!empty($req['info']))
        {
            $nodeInfo->setInfo($req['info']);
        }
    }
}

class NodeInfo
{
    /**
     * @var \swoole_server
     */
    static $serv;
    /**
     * 机器hostname
     */
    public $hostname;

    /**
     * IP列表
     */
    public $ipList;
    public $uanme;

    /**
     * 心跳时间
     */
    public $hearbeatTime;

    /**
     * 信息更新时间
     */
    public $updateTime;

    /**
     * 机器硬件设备信息
     */
    public $deviceInfo;

    public $address;
    public $port;

    /**
     * @param $info
     */
    function setInfo($info)
    {
        $this->updateTime = time();

        $this->ipList = $info['ipList'];
        $this->hostname = $info['hostname'];
        $this->uanme = $info['uanme'];
        $this->deviceInfo = $info['deviceInfo'];
    }

    /**
     * 发送指令
     * @param $req
     */
    function send($req)
    {
        self::$serv->sendto($this->address, $this->port, serialize($req));
    }
}