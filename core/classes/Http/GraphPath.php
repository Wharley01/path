<?php


namespace Path\Core\Http;


use Path\Core\Database\Model;
use Path\Core\Error\Exceptions\Path;
use Path\Core\Error\Exceptions\ResponseError;
use Path\Core\Router\Graph\Controller;
use Path\Core\Router\Route;
use Path\App\Controllers\Graph;

class GraphPath extends Route\Controller
{
    private $controller_namespace = "Path\\App\\Controllers\\Graph\\";
    private $request_method = "GET";
    private $auto_link = null;
    private $reserved_post_keys = ["_____graph", "_____method", "_____auto_link"];
    private $allowed_key_pattern = "/^([^\s\W]+)$/";

    public function onGet(Request $request, Response $response)
    {
        return $response->text(json_encode($request->getQuery()));
    }

    private function validateAllMiddleWare($middle_wares, $request, $response, $_path)
    {
        if (!is_array($middle_wares) || is_string($middle_wares))
            $middle_wares = [$middle_wares];


        foreach ($middle_wares as $middle_ware) {
            if ($middle_ware) {

                $argument = null;
                //            Load middleware class
                if(is_array($middle_ware)){
                    $ini_middleware = new $middle_ware[0]();
                    $argument = $middle_ware[1] ?? null;
                }elseif (is_string($middle_ware)){
                    $ini_middleware = new $middle_ware();
                }else{
                    throw new ResponseError('Invalid Middle passed');
                }
// TODO: added magic arg property
                $ini_middleware->arg = $argument;

                if ($ini_middleware instanceof MiddleWare) {
                    //            initialize middleware

                    //            Check middle ware return
                    $check_middle_ware = $ini_middleware->validate($request, $response);

                    if ($check_middle_ware === false) { //if the middle ware control method returns false
                        //                call the fall_back response
                        $fallback_response = $ini_middleware->fallBack($request, $response);
                        if (!is_null($fallback_response)) { //if user has a fallback method

                            if ($fallback_response && is_array($fallback_response)) {
                                return $fallback_response;
                            } elseif($fallback_response instanceof Response) {
                                return $fallback_response;
                            }else{
                                throw new ResponseError("Invalid middleware Response at $_path");
                            }
                        }else{
                            throw new ResponseError("Invalid middleware Response at $_path");
                        }
                    }else{
                        return false;
                    }
                } else {
                    throw new ResponseError("Expected \"{$middle_ware->method}\" to implement \"Path\\Http\\MiddleWare\" interface in \"{$_path}\"");
                }
            }
        }
        return false;
    }


    public function onPost(Request $request, Response $response)
    {
//        $query = $request->getAny('query');
//        $method = $request->getAny('__method');
        $query = $request->getPost('_____graph');
        $method = $request->getPost('_____method');
        $this->auto_link = $request->getPost('_____auto_link');
        $this->request_method = $method;
        parse_str($query,$query);
        if(!$query){
            return $response->error("Invalid graph structure");
        }

        try {
//            $response_data = [];
            $data = $this->generateResponseData($query,null);
            return $response->success('',$data);
        } catch (\Throwable $e) {
            if(method_exists($e,'getResponse')){
                $res = $e->getResponse();
                if($res){
                    return $res;
                }
            }

            return $response->error($e->getMessage());
        }
    }

    private function generateResponseData($query,$parent = null){
        $req = new Request();
        $req->METHOD = $this->request_method;
        $req->args = $parent;

        $res = new Response();
        $req->overridePost($this->getPostParams($req));

        $data = [];
        foreach ($query as $service => $structure){
            $service = $this->controller_namespace. $service;
            $service_name = $this->controller_namespace. $service;
            if(!class_exists($service)){
                throw new  ResponseError("Service \"".$service_name."\" does not exist" );
            }
            $service = new $service();
//            check for middleware

            $func = $structure['func'];

            if ($this->request_method === "POST"){
                $func = "set";
            }elseif ($this->request_method === "PATCH"){
                $func = "update";
            }


            try {
                $filters = $this->cleanAndValidateKeys($structure['filters']);
            } catch (Path $e) {
                throw new ResponseError($e->getMessage());
            }

            try {
                $params = $this->cleanAndValidateKeys($structure['params']);
                $req->setParams($params);
            } catch (Path $e) {
                throw new ResponseError($e->getMessage());
            }

            if($service instanceof Controller){
                if(method_exists($service,'schema')){
//
                    $schema = $service->schema();
                    if(!is_array($schema)){
                        throw new ResponseError("Error!: returned value of \"schema()\"  in ".$service_name." is expected to be an array, ".gettype($schema)." found" );
                    }

                    if(array_key_exists($func,$schema)){
                        $rules = $schema[$func];
//                        Check for middleware

                        //                        validate required params
                        if(array_key_exists('required_args',$rules) && $parent !== null){
                            $this->validateRequiredArgs($rules['required_args'],$parent,$func,$service_name);
                        }
//                        validate required params
                        if(array_key_exists('required_params',$rules)){
                            $this->validateRequiredParams($rules['required_params'],$req->params,$func,$service_name);
                        }
                        if(array_key_exists('middleware',$rules)){
                            $middleware_response = $this->validateAllMiddleWare(
                                $rules['middleware'] ?? [],
                                $req,
                                $res,
                                $service_name
                            );
                            if($middleware_response !== false){
                                if($parent === null){
                                    throw new ResponseError(null,0,null, $middleware_response);
                                }else{
                                    if($middleware_response instanceof Response){
                                        $r = json_decode($middleware_response->content);
                                        return property_exists($r,'data') ? $r->data : $r;
                                    }else{
                                        $r =  (object) $middleware_response;
                                        return property_exists($r,'data') ? $r->data : $r;
                                    }
                                }

                            }

                        }


//
                    }
                }

                if(!method_exists($service,$func)){
                    throw new ResponseError("Error!: trying to access service function \"".$func."\" that does not exist in ".$service_name );
                }

                if(!property_exists($service,'model')){
                    throw new ResponseError("Error!: $service_name does not have 'model' property" );
                }

                $model = $service->model;

                if($model instanceof Model){
                    $self_id = null;
                    if($this->auto_link && $parent){
                        $table_name = explode('\\',$service_name);
                        $table_name = $table_name[count($table_name)-1];
                        $self_id_key = strtolower($table_name).'_id';
                        $self_id = $parent->$self_id_key ?? '_____';
                    }
                    if($self_id){
                        $model->identify($self_id);
                    }elseif(array_key_exists('id',$filters)){
                        $id = $filters['id'];
                        if(!is_numeric($id)){
                            throw new ResponseError("ID must be numeric" );
                        }else{
                            $model->identify($id);
                        }
                        unset($filters['id']);
                    }
                    $model->where($filters);
//                    check if auto link is enabled

                }


                $columns = @$structure['columns'];
                if($columns){
                    $this->generateSelectColumns($model,$columns);
                }


                $res = $service->{$func}($req,$res);
                $message = "";
                if($res instanceof Response){
                    if($data = json_decode($res->content)){

                        if(!property_exists($data,'data')){
                            throw new ResponseError("Unable to find data key in response returned in $service_name->$func");
                        }else{
                            $data = $data->data;
                        }
                        $message = $data->message ?? "";
                    }else{
                        throw new ResponseError("Expected returned value of $service_name->$func to be either error/success/data of ".Response::class );
                    }
//                    loop through column

                    $this->getColumnData($data,$columns);

                    return $data;
                }else{
                    throw new ResponseError("Expected returned value of $service_name->$func to be Instance of ".Response::class );
                }
            }
        }
    }
    private function getPostParams(Request $req){
        $posts = $req->getPost();
        foreach ($this->reserved_post_keys as $_ => $key){
            unset($posts[$key]);
        }
        return array($posts);
    }
    private function generateSelectColumns(Model &$model,$columns){

        foreach ($columns as $column => $det){
            if($det['type'] == "column"){
                $model->select($column);
            }elseif ($det['type'] == "service"){
                $model->select("'service:{$det['service']}'")->as($column);
            }
        }

    }


    /*
     *
     *     return {
           str += `&${root}[${column.name}][type]=service`;
          str += `&${root}[${column.name}][func]=${column.method}`;
          str += `&${root}[${column.name}][service]=${column.service}`;
          str += `&${root}[${column.name}][filters]=${paramsToStr(column.filters)}`;
          str += `&${root}[${column.name}][params]=${paramsToStr(column.params)}`;
    }*/

    private function getColumnData(&$data,$columns){
        if (is_object($data)){
            foreach ($data as $key => $value){
                $column = @$columns[$key];
                if(@$column['type'] == "service"){
                    $data->$key = $this->generateResponseData([
                        $column['service'] => $column
                    ],$data);
                }
            }
        }elseif (is_array($data)){
            for ($n = 0; $n < count($data); $n ++){
                $_data = &$data[$n];
                foreach ($_data as $key => $value){

                    $column = @$columns[$key];
                    if(@$column['type'] == "service"){
                        $_data->$key = $this->generateResponseData([
                            $column['service'] => $column
                        ],$_data);
                    }
                }
            }
        }

    }

    private function cleanAndValidateKeys($data){
        $res = [];
        if($data = json_decode($data,true)){
            foreach ($data as $key => $value){
//                var_dump(preg_match_all("/[^\w_]/",$key));
                if(!preg_match_all($this->allowed_key_pattern,$key)){
                    throw new ResponseError("Invalid key \"$key\", keys must match $this->allowed_key_pattern");
                }else{
                    $res[$key] = $value;
                }
            }
            return $res;
        }else{
            return $res;
        }
    }
    public function onOptions(Request $request, Response $response)
    {
        return $response->success('');
    }

    private function validateRequiredArgs($required_args, $args, $func, $service)
    {
        foreach ($required_args as $index  => $arg){
            if(!property_exists($args,$arg)){
                throw new ResponseError("$func of  requires $arg Argument, make sure $arg is selected in your Parent service");
            }
        }
    }

    private function validateRequiredParams($required_params, $args, $func, $service)
    {
        foreach ($required_params as $index  => $arg){
            if(!property_exists($args,$arg)){
                throw new ResponseError("$func of  requires $arg parameter, make sure $arg is added to your parameter");
            }
        }
    }

}