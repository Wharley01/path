<?php
/**
 * Created by PhpStorm.
 * User: HP ENVY
 * Date: 10/22/2018
 * Time: 3:29 AM
 */

namespace Path\Controller;


use Data\Database;
use Path\Controller;
use Path\Http\Request;


class User implements Controller
{
    private $db_connection;

    public function __construct(Database $database)
    {
        $this->db_connection = $database;
    }
    public function Delete(Request $request){

    }
    public function Auth($params){
        return $params->user_id == 300;
    }

}