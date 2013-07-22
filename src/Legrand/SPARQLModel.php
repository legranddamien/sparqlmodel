<?php

//
// Copyright (c) 2013 Damien Legrand
// Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), 
// to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense,
// and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:
// The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
// 
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, 
// FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER 
// LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
//

namespace Legrand;

/**
*
* A model for Laravel 4, that can manage object through a graph database using SPARQL endpoint.
*
* @author Damien Legrand  < http://damienlegrand.com >
*/

use Illuminate\Support\Contracts\JsonableInterface;
use Illuminate\Support\Contracts\ArrayableInterface;
use Illuminate\Support\Facades\Config;
use Legrand\SPARQL;

class SPARQLModel implements JsonableInterface, ArrayableInterface {

        protected static $mapping       = [];
        protected static $multiMapping  = [];

        protected static $baseURI       = null;
        protected static $type          = null;
        protected static $status        = true;

        public $identifier              = null;
        public $inStore                 = false;

        public function select()
        {
                if($this->identifier == null || $this->identifier == "") throw new Exception('The identifier has no value');

                $sparql = new SPARQL();
                $sparql->baseUrl = Config::get('sparqlmodel.endpoint');

                $filter = "?uri = <".$this->identifier."> && (";
                $first = true;
                foreach ($this::$mapping as $k => $v) 
                {
                        if($first) $first = false; 
                        else $filter .= " || ";

                        $filter .= "?property = <$k>";
                }

                $filter .= ")";

                $sparql->select(Config::get('sparqlmodel.graph'))->where('?uri', '?property', '?value');

                if($this::$status) $sparql->where('?uri', '<' . Config::get('sparqlmodel.status') . '>', 1);

                $data = $sparql->filter($filter)->launch();

                if(!isset($data['results']) || !isset($data['results']['bindings']))
                {
                    return;
                }

                foreach ($data['results']['bindings'] as $value) 
                {
                        $this->process($value);
                }
        }

        public function process($value)
        {
                if(!isset($value['uri']['value']) || $value['uri']['value'] != $this->identifier) return;

                $property = '';
                if(isset($this::$mapping[$value['property']['value']])) $property = $this::$mapping[$value['property']['value']];
                else continue;

                $this->$property = $value['value']['value'];
                $this->inStore = true;
        }

        public function processLine($value)
        {
                if(!isset($value['uri']['value']) || $value['uri']['value'] != $this->identifier) return;

                foreach ($this::$mapping as $uri => $property) 
                {
                    if(isset($value[$property]))
                    {
                        $this->$property = $value[$property]['value'];
                    }
                }

                $this->inStore = true;
        }

        public function add($data)
        {
            foreach ($data as $key => $value) 
            {
                $this->$key = $value;
            }
        }

        public function listing($forProperty=false)
        {
                if($this->identifier == null || $this->identifier == "") throw new Exception('The identifier has no value');

                foreach ($this::$multiMapping as $k => $v) 
                {
                        if($forProperty != false)
                        {
                                if($v['property'] != $forProperty) continue;
                        }

                        $array = [];

                        $sparql = new SPARQL();
                        $sparql->baseUrl = Config::get('sparqlmodel.endpoint');

                        $elementMapping = call_user_func([$v['mapping'], 'getMapping']);

                        $sparql->select(Config::get('sparqlmodel.graph'))->distinct(true)
                                        ->where('<'.$this->identifier.'>', "<$k>", '?uri');

                        foreach ($elementMapping as $uri => $p) 
                        {
                                $sparql->optionalWhere('?uri', '<'.$uri.'>', "?$p");
                        }
                        
                        if(isset($v['order']) && count($v['order']) == 2) $sparql->orderBy($v['order'][0] . "(?" . $v['order'][1] . ")");
                        if(isset($v['limit']) && is_numeric($v['limit'])) $sparql->limit($v['limit']);

                        $data =  $sparql->launch();

                        if(!isset($data['results']) || !isset($data['results']['bindings']))
                        {
                            return;
                        }

                        foreach ($data['results']['bindings'] as $value) 
                        {
                                $found = false;
                                foreach ($array as $element) 
                                {
                                        if($element->identifier == $value['uri']['value'])
                                        {
                                                $found = true;
                                                $element->processLine($value);
                                                break;
                                        }
                                }

                                if(!$found)
                                {
                                        $newElement = new $v['mapping']();
                                        $newElement->identifier = $value['uri']['value'];
                                        $newElement->processLine($value);
                                        $array[] = $newElement;
                                }
                        }

                        $this->$v['property'] = $array;

                        if($forProperty != false) break;
                }

                return $this;
        }

        public function save($moreData=[])
        {
                if($this->inStore)
                {
                    //we update here so we delete triples

                    $filter = "";
                    foreach ($this::$mapping as $uri => $property) 
                    {
                            if(isset($this->$property) && ($uri != Config::get('sparqlmodel.created') || $uri != Config::get('sparqlmodel.updated')))
                            { 
                                if($filter != "") $filter .= " || ";
                                $filter .= "?x = <$uri>";
                            }
                    }

                    foreach ($moreData as $uri => $value)
                    {
                            if($filter != "") $filter .= " || ";
                            $filter .= "?x = <$uri>";
                    }

                    $filter .= " || ?x = <" . Config::get('sparqlmodel.updated') . ">";

                    $sparqlD = new SPARQL();
                    $sparqlD->baseUrl = Config::get('sparqlmodel.endpoint');

                    $sparqlD->delete(Config::get('sparqlmodel.graph'), '<' . $this->identifier . '> ?x ?y')
                            ->where('<' . $this->identifier . '>', '?x', '?y')
                            ->filter($filter)
                            ->launch();
                }
                else
                {
                        $this->identifier = $this->generateID();
                        $this->select();
                        if($this->inStore) return;
                }

                $this->identifier = $this->generateID();

                $sparql = new SPARQL();
                $sparql->baseUrl = Config::get('sparqlmodel.endpoint');

                $sparql->insert(Config::get('sparqlmodel.graph'));

                foreach ($this::$mapping as $uri => $property) 
                {
                        if(isset($this->$property))
                        { 
                            $p = (is_string($this->$property)) ? "'" . $this->$property . "'" : $this->$property;
                            $sparql->where('<' . $this->identifier . '>', '<' . $uri . '>', $p);
                        }
                }

                foreach ($moreData as $uri => $value)
                {
                        $p = (is_string($value)) ? "'" . $value . "'" : $value;
                        $sparql->where('<' . $this->identifier . '>', '<' . $uri . '>', $p);
                }

                if($this::$type != null) $sparql->where('<' . $this->identifier . '>', '<http://www.w3.org/1999/02/22-rdf-syntax-ns#type>', '<'.$this::$type.'>');

                if($this::$status) $sparql->where('<' . $this->identifier . '>', '<' . Config::get('sparqlmodel.status') . '>', 1);

                //$date = date('Y-m-d H:i:s', time());
                $date = date('c', time());

                if(!$this->inStore) $sparql->where('<' . $this->identifier . '>', '<' . Config::get('sparqlmodel.created') . '>', '"'.$date.'"^^xsd:dateTime');
                $sparql->where('<' . $this->identifier . '>', '<' . Config::get('sparqlmodel.updated') . '>', '"'.$date.'"^^xsd:dateTime');

                $data = $sparql->launch();

                $this->inStore = true;
        }



        public function delete($logicDelete=false)
        {
                if(!$this->inStore) return;

                $sparql = new SPARQL();
                $sparql->baseUrl = Config::get('sparqlmodel.endpoint');

                if(!$logicDelete)
                {
                    //Real delete
                    $sparql->delete(Config::get('sparqlmodel.graph'), '<' . $this->identifier . '> ?x ?y')
                            ->where('<' . $this->identifier . '>', '?x', '?y');
                }
                else
                {
                    //Logic Delete
                    $sparql->delete(Config::get('sparqlmodel.graph'), '<' . $this->identifier . '> <' . Config::get('sparqlmodel.status') . '> ?y')
                            ->where('<' . $this->identifier . '>', '<' . Config::get('sparqlmodel.status') . '>', '?y');
                }

                $data = $sparql->launch();

                if($logicDelete)
                {
                    $sparql2 = new SPARQL();
                    $sparql2->baseUrl = Config::get('sparqlmodel.endpoint');
                    $sparql2->insert(Config::get('sparqlmodel.graph'))
                            ->where('<' . $this->identifier . '>', '<' . Config::get('sparqlmodel.status') . '>', 2)->launch();
                }

                $this->inStore = false;
        }

        public function link($object)
        {
                if($this::$multiMapping == null) return;

                $c = get_class($object);
                $map = null;

                foreach ($this::$multiMapping as $key => $value)
                {
                    if(isset($value['mapping']) && $value['mapping'] == $c)
                    {
                            $map = $key;
                            break;
                    }
                }

                if($map == null) return;

                $sparql = new SPARQL();
                $sparql->baseUrl = Config::get('sparqlmodel.endpoint');
                $sparql->insert(Config::get('sparqlmodel.graph'))
                        ->where('<' . $this->identifier . '>', '<' . $map . '>', '<' . $object->identifier . '>')
                        ->launch();
        }

        public static function find($id)
        {
                $class = get_called_class();
                $m = new $class;

                if((strlen($id) < 7 || substr($id, 0, 7) != 'http://') && static::$baseURI != null) $id = static::$baseURI . $id;

                $m->identifier = $id;
                $m->select();

                return $m;
        }

        public function exist()
        {
            return $this->inStore;
        }

        public static function getMapping()
        {
                return static::$mapping;
        }

        /**
         * Convert the object to its JSON representation.
         *
         * @param  int  $options
         * @return string
         */
        public function toJson($options = 0)
        {
                return json_encode($this->toArray(), $options);
        }

        /**
         * Get the instance as an array.
         *
         * @return array
         */
        public function toArray()
        {
                $object = [];
                $object['id'] = $this->identifier;

                foreach ($this::$mapping as $key => $value) 
                {
                        if(isset($this->$value)) $object[$value] = $this->$value;
                }

                foreach ($this::$multiMapping as $key => $value) 
                {
                        $p = $value['property'];
                        if(isset($this->$p) && is_array($this->$p) && count($this->$p) > 0)
                        {
                                $element = $this->$p;
                                $element = $element[0];

                                if(!is_object($element))
                                {
                                        $object[$p] = $this->$p;
                                        break;
                                }

                                $implements = class_implements(get_class($element));
                                if(in_array('Illuminate\Support\Contracts\ArrayableInterface', $implements))
                                {
                                        $object[$p] = [];
                                        foreach ($this->$p as $o) 
                                        {
                                                $object[$p][] = $o->toArray();
                                        }
                                }
                                else
                                {
                                        $object[$p] = $this->$p;
                                }
                        }
                        elseif(isset($this->$p)) $object[$p] = $this->$p;
                }

                return $object;
        }

        public function generateID()
        {
            throw new Exception("generatedID method has to be overrided");
        }
}