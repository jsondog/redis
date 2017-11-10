<?php
include 'config.php';
class Model
{
    //用户名
    protected $user;
    //密码
    protected $pwd;
    //主机
    protected $host;
    //库名，是一个数组
    protected $dbName = array();
    //字符集
    protected $charset = 'utf8';
    //连接资源是一个数组
    protected $_link = array();
    //通用表名
    protected $tabName;    
    //真实表名
    protected $trueTabName;
    //表前缀
    protected $prefix;
    //字段缓存
    protected $fields;

    //sql语句
    protected $sql = '';
    //创建表的sql语句
    protected $createSql = '';

    //1,通过ID取余，得到真实表名    mod
    //2,用户名截取前几位   substr
    //3,md5                        md5
    //4,不带分库分表        none

    protected $partition = array(
        'type' => 'md5',

        'rule' => 3,

    );

    public function __construct($tabName = '')
    {
        $this->user = DB_USER;
        $this->host = DB_HOST;
        $this->dbName[0] = DB_NAME;
        $this->charset = DB_CHARSET;
        $this->prefix = DB_PREFIX;
        $this->pwd = DB_PWD;

        if (empty($tabName)) {
            //userModel
            //newModel
            $this->tabName = $this->prefix . strtolower(substr(get_class($this), 0, -5));

        } else {
            $this->tabName = $this->prefix . $tabName;
        }

        //默认先连接一台数据库
        $this->_link[0] = $this->connect($this->host, $this->user, $this->pwd, $this->dbName, $this->charset);

    }

    //链接数据库
    public function connect($host, $user, $pwd, $dbName, $charset, $linkId = 0)
    {
        $conn = mysqli_connect($host, $user, $pwd);

        if (mysqli_errno($conn)) {
            $this->error(-1, $conn);
            return false;
        }

        if (!$this->selectDb($dbName[$linkId], $conn)) {
            $this->error(-2, $conn);
            return false;
        }

        if (!$this->setCharset($charset, $conn)) {
            $this->error(-3, $conn);
            return false;
        }

        return $conn;

    }

    public function selectDb($dbName, $conn)
    {
        if (mysqli_select_db($conn, $dbName)) {

            return true;
        } else {
            return false;
        }
    }

    public function setCharset($charset, $conn)
    {
        if (mysqli_set_charset($conn, $charset)) {
            return true;
        } else {
            return false;
        }

    }

    //分机器，手动调用
    public function addServer($host, $user, $pwd, $dbName, $charset, $linkId)
    {
        $this->dbName[$linkId] = $dbName;
        $this->_link[$linkId] = $this->connect($host, $user, $pwd, $dbName, $charset, $linkId);

    }

    //获取真实的表名
    public function getTrueTable($content, $linkId = 0)
    {
        switch ($this->partition['type']) {
            case 'mod':
                if (!is_int($content)) {
                    $this->error(-4);
                    return false;
                }
                $string = $content % $this->partition['rule'];
                break;
            case 'substr':
                $string = substr($content, 0, $this->partition['rule']);
                break;
            case 'md5':
                $string = substr(md5($content), 0, $this->partition['rule']);
                break;
            case 'none':
                $string = null;
                break;
        }

        if (empty($string)) {
            $this->trueTableName = $this->tabName;

        } else {
            //a_user_57
            $this->trueTableName = $this->tabName . '_' . $string;
        }
        //第一，判断表是否存在，存在返回表字段缓存
        //第二，不存在，则创建表，返回字段缓存

        $this->existsTable($this->trueTableName, $linkId);

    }
    //表是否存在
    //是否缓存了字段
    protected function existsTable($tableName, $linkId = 0)
    {
        $database = $this->dbName[$linkId];
        $sql = 'select `TABLE_NAME` from `INFORMATION_SCHEMA`.`TABLES` where `TABLE_SCHEMA`=\'' . $database . '\' and `TABLE_NAME`=\'' . $tableName . '\'';
        if ($this->execute($sql, $linkId)) {
            //表存在
            if (file_exists('cache/' . md5($this->tabName) . '.php')) {
                $this->fields = include 'cache/' . md5($this->tabName) . '.php';
            } else {
                //暂时留着不写，待会来写
                $this->fields = $this->getFieldCache($linkId);
            }

        } else {
            //表不存在
            $this->createTable($this->trueTableName, $linkId);
            $this->fields = $this->getFieldCache($linkId);

        }

    }

    //获取字段缓存
    protected function getFieldCache($linkId = 0)
    {
        if (file_exists('cache/' . md5($this->tabName) . '.php')) {
            $fields = include 'cache/' . md5($this->tabName) . '.php';
            return $fields;
        }
        $sql = "desc $this->trueTableName";
        $f = $this->query($sql, $linkId);
        $fields = $this->writeFields($f);

        return $fields;

    }

    //把字段缓存写到文件中
    protected function writeFields($f)
    {
        foreach ($f as $key => $value) {
            $fields[] = $value['Field'];

            if ($value['Key'] == 'PRI') {
                $fields['_pk'] = $value['Field'];
            }
            if ($value['Extra'] == 'auto_increment') {
                $fields['_auto'] = $value['Field'];
            }
        }
        $string = "<?php \n return " . var_export($fields, true) . "\n?>";

        file_put_contents('cache/' . md5($this->tabName) . '.php', $string);
        return $fields;

    }

    //真正创建表的方法
    protected function createTable($tabName, $linkId = 0)
    {
        $sql = str_replace('__TABLENAME__', $tabName, $this->createSql);

        $this->execute($sql, $linkId);
    }

    //不需要返回结果集我用execute方法
    public function execute($sql, $linkId = 0)
    {
        $this->sql = $sql;
        $conn = $this->_link[$linkId];
        $result = mysqli_query($conn, $sql);
        if ($result && mysqli_affected_rows($conn)) {
            return mysqli_affected_rows($conn);
        } else {
            return false;
        }

    }

    //需要返回结果集我用query方法
    public function query($sql, $linkId = 0)
    {
        $result = mysqli_query($this->_link[$linkId], $sql);

        if ($result && mysqli_affected_rows($this->_link[$linkId])) {
            while ($row = mysqli_fetch_assoc($result)) {

                $rows[] = $row;
            }
        } else {
            return false;
        }
        return $rows;
    }

    public function error($num, $conn)
    {
        switch ($num) {
            case -1:
                $string = '连接数据库服务器失败' . mysql_error($conn);
                break;
            case -2:
                $string = '选择数据失败';
                break;
            case -3:
                $string = '设置字符集失败';
                break;
            case -4:
                $string = '数据库路由时选择的是取余，传入的不是整型';
                break;
        }
    }

    //查最大值
    public function max($field, $linkId = 0)
    {
        if (!in_array($field, $this->fields)) {
            return false;
        }
        $sql = "select max($field) as re from $this->trueTableName";
        $result = $this->query($sql, $linkId);
        $row = $result['re'];
        return $row;

    }

    //查最小值
    public function min($field, $linkId = 0)
    {
        if (!in_array($field, $this->fields)) {
            return false;
        }
        $sql = "select min($field) as re from $this->trueTableName";
        $result = $this->query($sql, $linkId);
        $row = $result['re'];
        return $row;

    }
    //求和
    public function sum($field, $linkId = 0)
    {
        if (!in_array($field, $this->fields)) {
            return false;
        }
        $sql = "select sum($field) as re from $this->trueTableName";
        $result = $this->query($sql, $linkId);
        $row = $result['re'];
        return $row;

    }
    //最平均数
    public function avg($field, $linkId = 0)
    {
        if (!in_array($field, $this->fields)) {
            return false;
        }
        $sql = "select avg($field) as re from $this->trueTableName";
        $result = $this->query($sql, $linkId);
        $row = $result['re'];
        return $row;

    }
    //求总数
    public function count($field = '', $linkId = 0)
    {
        if (empty($field)) {
            $field = $this->fields['_pk'];
        }
        $sql = "select count($field) as re from $this->trueTableName";
        $result = $this->query($sql, $linkId);
        $row = $result['re'];
        return $row;
    }
    //
    //删除
    public function delete($data, $where = '', $linkId = 0, $order = '', $limit = '')
    {
        //delete from 表  where 字段  order by  字段 limit

        if (is_array($data)) {
            $value = join(',', $data);
        } else {
            $value = (int) $data;
        }
        $fields = $this->fields['_pk'];

        if (empty($where)) {

            $sql = "delete from $this->trueTableName where $fields in ($value)";
        } else {
            $where = 'where ' . $where;
            if (!empty($order)) {
                $order = 'order by ' . $order;
            }
            if (!empty($limit)) {
                $limit = 'limit ' . $limit;
            }

            $sql = "delete from $this->trueTableName $where $order $limit";
        }
        return $this->execute($sql, $linkId);
    }
    //
    //修改
    public function save($data, $where, $linkId = 0, $order = '', $limit = '')
    {

        //update 表  set 字段=值,字段=值 where 条件 order  limit
        $key = array_keys($data);
        $newKey = array_intersect($key, $this->fields);

        foreach ($data as $key => $value) {
            if (!in_array($key, $newKey)) {
                continue;
            }

            $update .= $key . '="' . $value . '",';

        }
        $update = rtrim($update, ',');

        if (!empty($order)) {
            $order = 'order by ' . $order;
        }
        if (!empty($limit)) {
            $limit = 'limit ' . $limit;
        }

        if (!empty($where)) {
            $where = 'where ' . $where;
        }

        $sql = "update $this->trueTableName set $update $where $order $limit";

        echo $sql;
        $result = $this->execute($sql, $linkId);
        return $result;

    }

    //增加
    public function add($data, $linkId = 0)
    {
        //insert into 表(字段) values(值)
        $values = '';
        $key = array_keys($data);
        $newKey = array_intersect($key, $this->fields);
        foreach ($data as $key => $value) {
            if (!in_array($key, $newKey)) {
                continue;
            }

            $values .= "'" . $value . "',";
        }
        $values = trim($values, ',');
        $fields = join(',', $newKey);
        $sql = "insert into $this->trueTableName($fields) values($values)";
        $result = $this->execute($sql, $linkId);
        return $result;
    }

    //单条查询
    public function find($linkId = 0, $where = '', $order = '')
    {
        //select * from 表 where  order  limit 1
        $field = join(',', $this->fields);
        if (!empty($where)) {
            $where = 'where ' . $where;
        }
        if (!empty($order)) {
            $order = 'order by ' . $order;
        }
        $sql = "select $field from $this->trueTableName $where $order limit 1";
        $result = $this->query($sql, $linkId);
        return $result[0];

    }

    //多条查询
    public function select($field = '', $linkId = 0, $where = '', $order = '', $limit = '')
    {
        //select * from 表 where  order  limit
        if (empty($field)) {
            $fields = join(',', $this->fields);
        } else {
            if (is_array($field)) {
                $newKey = array_intersect($field, $this->fields);
                $fields = implode(',', $newKey);
            } else {
                $fields = $field;
            }
        }
        if (!empty($where)) {
            $where = 'where ' . $where;
        }
        if (!empty($order)) {
            $order = 'order by ' . $order;
        }
        if (!empty($limit)) {
            $limit = 'limit ' . $limit;
        }
        $sql = "select $fields from $this->trueTableName $where $order $limit";
        $result = $this->query($sql, $linkId);
        return $result;

    }
    //按照字段来查询数据

    public function __call($name, $param)
    {
        $key = substr($name, 0, 5);
        if (strtolower($key) == 'getby') {
            $field = strtolower(substr($name, 5));

            if (!in_array($field, $this->fields)) {
                return false;
            }
            $f = join(',', $this->fields);
            $value = $param[0];
            $sql = "select $f  from $this->trueTableName where $field='$value'";

            $result = $this->query($sql);
            return $result[0];

        }
    }

    public function __get($name)
    {
        if ($name == 'sql') {
            return $this->sql;
        }
    }

}
