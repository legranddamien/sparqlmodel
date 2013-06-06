# SPARQLModel for Laravel (PHP)

A model for Laravel 4, that can manage object through a graph database using SPARQL endpoint.

## How to use this model

First of all you need to have access to an endpoint. If you need to perform INSERT and DELETE, make sur you have these privileges.

### Install

To install SPARQLModel in your Laravel 4 project, update your composer.json file by adding this line in the require array:

        "legrand/sparqlmodel": "dev-master"

After that you can open a terminal in the root folder of your project and run composer :

        composer update

it will add the package in your project.

### Configuration

This model need some configurations to work. Create a 'sparqlmodel.php' file in app/config and add this arry :

        <?php

        return array(

                'endpoint' => 'http://localhost:8890/sparql',
                'graph' => 'http://localhost:8890/DAV',

                'status' => 'http://uri.for/property/status',
                'created' => 'http://uri.for/property/created',
                'updated' => 'http://uri.for/property/updated'
        );

The **endpoint** is the URL to communicate with the graph database through SPARQL, and **graph** is the URI of the graph that you are using for your application.

### Example

Now you can create a simple model. For example here a **Like** model : 

        use Legrand\SPARQLModel;

        class Like extends SPARQLModel {

                public $hash                    = null;
                protected static $baseURI       = "http://semreco/like/";
                protected static $type          = "http://semreco/class/Like";
                protected static $mapping       = [
                        'http://semreco/property/resource' => 'topic',
                        'http://semreco/property/performed' => 'performed'
                ];

                public function generateID()
                {
                        if(!isset($this->hash) || !is_string($this->hash) || $this->hash == '') throw new Exception("There is no hash string to generate the unique URI");
                        return $this::$baseURI . $this->hash;
                }

                public function save($moreData=[])
                {
                        if(!isset($this->performed)) $this->performed = date('Y-m-d H:i:s', time());
                        parent::save($moreData);
                }
        }

Here you can some attributes that you need to specify

- $baseURI : Here you give the base of the URI, the part taht identify the like will be append at the end of this string
- $type : Here you give the class URI that define the object
- $mapping : You give here an array where a property uri correspond to an attribute name, so for examp;e to get the date of the like you can do $like->performed

There are also some some methods that are overided here :

- generateID() : as data id are URI, we cannot generate a unique id (with increment for example) so you need to return here the URI that correspond to your resource
- save($moreData=[]) : here this method have been overrided to save automatically the date of this like

Now we can create a **User** model : 

        <?php

        use Legrand\SPARQLModel;

        class User extends SPARQLModel {

                protected static $baseURI       = "http://semreco/person/";
                protected static $type          = "http://xmlns.com/foaf/Person";
                protected static $mapping       = [
                        'http://xmlns.com/foaf/lastName' => 'lastname',
                        'http://xmlns.com/foaf/firstName' => 'firstname',
                        'http://xmlns.com/foaf/mbox' => 'email'
                ];
                protected static $multiMapping  = [
                        'http://semreco/property/like' => [
                                'property' => 'likes',
                                'mapping' => 'Like', //should be the name of the corresponding class
                                'order' => ['DESC', 'performed'], //performed is in the mapping of the Like model
                                'limit' => 5
                        ]
                ];

                public function generateID()
                {
                        if(!isset($this->username)) throw new Exception("There is no username to generate the unique URI");
                        return $this::$baseURI . $this->username;
                }

                public function addLike($uri)
                {
                        if(!$this->inStore) return; //isStore is usefull to see if the current object exist in database

                        $like = new Like();
                        $ex = explode('/', $uri);
                        $like->hash = $ex[count($ex)-1] . '_(' . $this->username . ')';
                        $like->topic = $uri;
                        $like->save(); // we save in database the like object

                        if(!isset($this->likes) || !is_array($this->likes)) $this->likes = [];

                        $this->likes[] = $like;

                        $this->link($like); //here we add the like to the current user, this method will user the $multiMapping attribute
                }
        }

Here another attribut is used to make a link between the user and his likes : 

- $multiMapping : need a complexe array. The URI of the property need another array with : 
        - *property* : the name of the attribute
        - *mapping* : the name of the class, 
        - *order* : an array with first value the ordering type and second value the name of the attribute
        - *limit* : the number of resources to get

You can see also that a method addLike get the uri of the resource that have been liked by the user to create a like and add it to the user's likes.

### Usage

Now we can use our models likes this to get an existing user:

        $user = new User::find('damien_legrand'); // Same as new User::find('http://semreco/person/damien_legrand');

        $user->addLike('http://dbpedia.org/resource/Nine_Inch_Nails');

        return $user->firstname; // will return 'Damien'

Or to create a user : 
        
        $user = new User();
        $user->firstname = "Damien";
        $user->lastname = "Legrand";
        $user->save(); // save the user in database

## More to come

 - Better documentation
 - **Created** and **Updated** dates management
 - Where conditions
 - ...