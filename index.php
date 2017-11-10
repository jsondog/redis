<?php
include 'UserModel.php';
$model = new UserModel();
$username = 'dabobo'; //可以从前端获取  
$model->getTrueTable($username);


$time = time();
$model->add(array('username' => $username, 'password' => md5('123456'), 'create_time' => $time, 'update_time' => $time));

var_dump($model->sql);

$info = $model->getByUsername($username);

var_dump($info);

var_dump($model->sql);

#var_dump($info);

