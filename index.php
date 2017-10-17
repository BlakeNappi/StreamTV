<?php

/****************************************************************************
----------------------------BLAKE C. NAPPI-----------------------------------
StreamTV PhP / MySQL / Silex Demonstration

This program is designed to demonstrate how to use PhP, MySQL and Silex to 
implement a web application that accesses a database.

Files:  The application is made up of the following files

php: 	index.php - This file has all of the php code in one place.  It is found in 
		the public_html/toystore/ directory of the code source.
		
		connect.php - This file contains the specific information for connecting to the
		database.  It is stored two levels above the index.php file to prevent the db 
		password from being viewable.
		
twig:	The twig files are used to set up templates for the html pages in the application.
		There are 7 twig files:
		- home.twig - home page for the web site
		- footer.twig - common footer for each of he html files
		- header.twig - common header for each of the html files
		- form.html.twig - template for forms html files (login and register)
		- item.html.twig - template for toy information to be displayed
		- orders.html.twig - template for displaying orders made by a customer
		- search.html.twig - template for search results
		
		The twig files are found in the public_html/toystore/views directory of the source code
		
Silex Files:  Composer was used to compose the needed Service Providers from the Silex 
		Framework.  The code created by composer is found in the vendor directory of the
		source code.  This folder should be stored in a directory called toystore that is 
		at the root level of the application.  This code is used by this application and 
		has not been modified.


*****************************************************************************/

// Set time zone  
date_default_timezone_set('America/New_York');

/****************************************************************************   
Silex Setup:
The following code is necessary for one time setup for Silex 
It uses the appropriate services from Silex and Symfony and it
registers the services to the application.
*****************************************************************************/
// Objects we use directly
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;
use Silex\Provider\FormServiceProvider;

// Pull in the Silex code stored in the vendor directory
require_once __DIR__.'/../../silex-files/vendor/autoload.php';

// Create the main application object
$app = new Silex\Application();

// For development, show exceptions in browser
$app['debug'] = true;

// For logging support
$app->register(new Silex\Provider\MonologServiceProvider(), array(
    'monolog.logfile' => __DIR__.'/development.log',
));

// Register validation handler for forms
$app->register(new Silex\Provider\ValidatorServiceProvider());

// Register form handler
$app->register(new FormServiceProvider());

// Register the session service provider for session handling
$app->register(new Silex\Provider\SessionServiceProvider());

// We don't have any translations for our forms, so avoid errors
$app->register(new Silex\Provider\TranslationServiceProvider(), array(
        'translator.messages' => array(),
    ));

// Register the TwigServiceProvider to allow for templating HTML
$app->register(new Silex\Provider\TwigServiceProvider(), array(
        'twig.path' => __DIR__.'/views',
    ));

// Change the default layout 
// Requires including boostrap.css
$app['twig.form.templates'] = array('bootstrap_3_layout.html.twig');

/*************************************************************************
 Database Connection and Queries:
 The following code creates a function that is used throughout the program
 to query the MySQL database.  This section of code also includes the connection
 to the database.  This connection only has to be done once, and the $db object
 is used by the other code.

*****************************************************************************/
// Function for making queries.  The function requires the database connection
// object, the query string with parameters, and the array of parameters to bind
// in the query.  The function uses PDO prepared query statements.

function queryDB($db, $query, $params) {
    // Silex will catch the exception
    $stmt = $db->prepare($query);
    $results = $stmt->execute($params);
    $selectpos = stripos($query, "select");
    if (($selectpos !== false) && ($selectpos < 6)) {
        $results = $stmt->fetchAll();
    }
    return $results;
}



// Connect to the Database at startup, and let Silex catch errors
$app->before(function () use ($app) {
    include '../../connect.php';
    $app['db'] = $db;
});

/*************************************************************************
 Application Code:
 The following code implements the various functionalities of the application, usually
 through different pages.  Each section uses the Silex $app to set up the variables,
 database queries and forms.  Then it renders the pages using twig.

*****************************************************************************/

// Login Page

$app->match('/login', function (Request $request) use ($app) {
	// Use Silex app to create a form with the specified parameters - username and password
	// Form validation is automatically handled using the constraints specified for each
	// parameter
    $form = $app['form.factory']->createBuilder('form')
        ->add('uname', 'text', array(
            'label' => 'User Name',
            'constraints' => array(new Assert\NotBlank())
        ))
        ->add('password', 'password', array(
            'label' => 'Password',
            'constraints' => array(new Assert\NotBlank())
        ))
        ->add('login', 'submit', array('label'=>'Login'))
        ->getForm();
    $form->handleRequest($request);

    // Once the form is validated, get the data from the form and query the database to 
    // verify the username and password are correct
    $msg = '';
    if ($form->isValid()) {
        $db = $app['db'];
        $regform = $form->getData();
        $uname = $regform['uname'];
        $pword = $regform['password'];
        $query = "select password, custID
        			from customer
        			where username = ?";
        $results = queryDB($db, $query, array($uname));
        # Ensure we only get one entry
        if (sizeof($results) == 1) {
            $retrievedPwd = $results[0][0];
             $custID = $results[0][1];
            

            // If the username and password are correct, create a login session for the user
            // The session variables are the username and the customer ID to be used in 
            // other queries for lookup.
            if (password_verify($pword, $retrievedPwd)) {
                $app['session']->set('is_user', true);
                $app['session']->set('user', $uname);
                $app['session']->set('custID', $custID);
                
                return $app->redirect('/streamtv/');
            }
        }
        else {
        	$msg = 'Invalid User Name or Password - Try again';
        }
        
    }
    // Use the twig form template to display the login page
    return $app['twig']->render('form.html.twig', array(
        'pageTitle' => 'Login',
        'form' => $form->createView(),
        'results' => $msg
    ));
});



// *************************************************************************

// Registration Page

$app->match('/register', function (Request $request) use ($app) {
    $form = $app['form.factory']->createBuilder('form')
        ->add('uname', 'text', array(
            'label' => 'User Name',
            'constraints' => array(new Assert\NotBlank(), new Assert\Length(array('min' => 5)))
        ))
        ->add('password', 'repeated', array(
            'type' => 'password',
            'invalid_message' => 'Password and Verify Password must match',
            'first_options'  => array('label' => 'Password'),
            'second_options' => array('label' => 'Verify Password'),    
            'constraints' => array(new Assert\NotBlank(), new Assert\Length(array('min' => 5)))
        ))

        ->add('fname', 'text', array(
            'label' => 'First Name',
            'constraints' => array(new Assert\NotBlank())
        ))
        
        ->add('lname', 'text', array(
            'label' => 'Last Name',
            'constraints' => array(new Assert\NotBlank())
        ))


        ->add('email', 'text', array(
            'label' => 'Email',
            'constraints' => new Assert\Email()
        ))
        
        ->add('creditcard', 'text', array(
            'label' => 'Credit Card',
            'constraints' => array(new Assert\NotBlank())
        ))
        
        ->add('submit', 'submit', array('label'=>'Register'))
        ->getForm();

    $form->handleRequest($request);

    if ($form->isValid()) {
        $regform = $form->getData();
        $uname = $regform['uname'];
        $pword = $regform['password'];
        $fname = $regform['fname'];
        $lname = $regform['lname'];
        $email = $regform['email'];
        $creditcard = $regform['creditcard'];
        
        // Check to make sure the username is not already in use
        // If it is, display already in use message
        // If not, hash the password and insert the new customer into the database
        $db = $app['db'];
        $query = 'select * from customer where username = ?';
        $results = queryDB($db, $query, array($uname));
        if ($results) {
    		return $app['twig']->render('form.html.twig', array(
        		'pageTitle' => 'Register',
        		'form' => $form->createView(),
        		'results' => 'Username already exists - Try again'
        	));
        }
        else { 
		$query = "select RIGHT(max(c.custID),3) from customer c"; // get the # newest customer's custID
        	$custID = queryDB($db, $query, array());
        	$newID = $custID[0][0];
        	$newID = (integer)$newID + 1;	//increment
        	$newID = 'cust0' . $newID; // put back into cust
        		
        	$membersince = date("Y-m-d");	//current date
        	$renewaldate = date("Y-m-d");   	
        		
			$hashed_pword = password_hash($pword, PASSWORD_DEFAULT);
			$insertData = array($uname, $hashed_pword, $fname, $lname, $email, $creditcard, $newID, $membersince, $renewaldate);
			
		
       	 	$query = 'insert into customer 
        				(username, password, fname, lname, email, creditcard, custID, membersince, renewaldate)
        				values (?, ?, ?, ?, ?, ?, ?, ?, ?)';
        	$results = queryDB($db, $query, $insertData);
	        // Maybe already log the user in, if not validating email
        	return $app->redirect('/streamtv/');
        }
    }
    return $app['twig']->render('form.html.twig', array(
        'pageTitle' => 'Register',
        'form' => $form->createView(),
        'results' => ''
    ));   
});

// *************************************************************************
 
// Actor Result Page

$app->get('/actor/{actorID}', function (Silex\Application $app, $actorID) {
    // Create query to get the toy with the given actorID
    $db = $app['db'];
    $query = "SELECT actorID, fname, lname
    	 FROM actors
    	 WHERE actorID = ?";
    	 
    $results = queryDB($db, $query, array($actorID));
    
    $query = "SELECT s.title, mc.role, mc.showID
    		FROM main_cast mc, shows s
    		WHERE mc.actorID = ? AND
    			mc.showID = s.showID";
    $mc = queryDB($db, $query, array($actorID));		
    
    $query = "SELECT DISTINCT s.title, rc.role, s.showID
    		FROM recurring_cast rc, shows s
    		WHERE rc.actorID = ? AND
    			rc.showID = s.showID";
    			
    $rc = queryDB($db, $query, array($actorID));
    
    // Display results in item page
    return $app['twig']->render('actor.html.twig', array(
        'pageTitle' => $results[0]['fname'],
        'results' => $results,
        'mc' => $mc,
        'rc' => $rc
    ));
});

// *************************************************************************
// shows Result Page

$app->get('/shows/{showID}', function (Silex\Application $app, $showID) {
    // Create query to get the toy with the given toynum
    $db = $app['db'];

    //get custID & username if logged in
    if ($app['session']->get('is_user')) {
    	//logged in
	$user = $app['session']->get('user');
	$custID = $app['session']->get('custID');
    }else{
    	//not logged in
	$user = '';
    }

    $query = "SELECT *
              FROM shows as s
              WHERE s.showID = ?";


    $results = queryDB($db, $query, array($showID));


    $query = "SELECT a.fname, a.lname, m.role, a.actorID, s.showID
              FROM main_cast as m, shows as s, actors as a
              WHERE s.showID = ? AND 
             	 	a.actorID = m.actorID AND 
              		s.showID= m.showID";

    $mc= queryDB($db, $query, array($showID));
    
    //query for recurring cast information
    $query = "select count(a.actorID) as acount, a.fname, a.lname, a.actorID, rc.role 
		from actors a, recurring_cast rc, shows s, episode e 
	    	where e.episodeID = rc.episodeID and 
			rc.showID = s.showID and 
			e.showID = s.showID and 
			a.actorID = rc.actorID and 
			s.showID = ? 
		group by a.actorID";
	
    $rc = queryDB($db, $query, array($showID));

    $query = "SELECT q.showID 
    		FROM customer c, cust_queue q 
    		WHERE q.custID = c.custID AND
    		q.showID = ? AND c.custID = ?";
    		
    $inqueue = queryDB($db, $query, array($showID,$custID));
	

    if($inqueue != null){
	    // Display results in item page
	    return $app['twig']->render('shows.html.twig', array(
	        'pageTitle' => $results[0]['fname'],
	        'results' => $results,
	        'mc' => $mc,
	        'rc' => $rc,
	        'user' => $user,
	        'inqueue' => $inqueue
	
	    ));
    }else{
    	return $app['twig']->render('shows.html.twig', array(
	        'pageTitle' => $results[0]['fname'],
	        'results' => $results,
	        'mc' => $mc,
	        'rc' => $rc,
	        'user' => $user,
	        'inqueue' => ''
    
	));
	}
});

// *************************************************************************
// show_episodes Result Page

$app->get('/show_episodes/{showID}', function (Silex\Application $app, $showID) {
    // Create query to get the toy with the given toynum
    $db = $app['db'];


    $query = "SELECT *, LEFT(episodeID, 1)
              FROM episode e
              WHERE showID = ?";


    $episodes = queryDB($db, $query, array($showID));

    $query = "SELECT showID, title
              FROM shows
              WHERE showID = ?";


    $shows = queryDB($db, $query, array($showID));

    
    // Display results in item page
    return $app['twig']->render('show_episodes.html.twig', array(
        'pageTitle' => $results[0]['showID'],

// MADE CHANGES HERE!!!!!!!!!!!!!!!!!!!
        'episodes' => $episodes,
        'shows' => $shows
    ));
});


// *************************************************************************
// episodeinfo Result Page

$app->get('/episodeinfo/{showID}&{episodeID}', function (Silex\Application $app, $showID, $episodeID) {
    // Create query to get the toy with the given toynum
    $db = $app['db'];

    //is user logged in?
    if ($app['session']->get('is_user')) {
	$user = $app['session']->get('user');}
    else{$user = '';}

    //query for episode info
    $query = "SELECT e.showID, title, airdate, episodeID
              FROM episode e
              WHERE showID = ? AND episodeID = ?";


    $episodes = queryDB($db, $query, array($showID, $episodeID));

    //query for show info
    $query = "SELECT showID, title
              FROM shows
              WHERE showID = ?";


    $shows = queryDB($db, $query, array($showID));
    
    //query for main cast
    $query = "select distinct mc.role, a.fname, a.lname, a.actorID AS anum 
    		FROM actors a, main_cast mc, shows s 
    		WHERE s.showID = mc.showID AND 
    		a.actorID = mc.actorID AND
    		s.showID = ?";
    		
    $mc = queryDB($db, $query, array($showID));
	
    //query for recuring cast
    $query = "select distinct rc.role, a.fname, a.lname, a.actorID AS anum 
    		FROM actors a, recurring_cast rc, shows s, episode e 
    		WHERE s.showID = rc.showID and 
    		a.actorID = rc.actorID AND
    		rc.episodeID = e.episodeID 
    		AND s.showID = ? 
    		AND e.episodeID = ?";
    		
    $rc = queryDB($db, $query, array($showID, $episodeID));	
    
    // Display results in item page
    return $app['twig']->render('episodeinfo.html.twig', array(
        'pageTitle' => $results[0]['showID'],

	// set variables for page
        'episodes' => $episodes,
        'shows' => $shows,
        'rc' => $rc,
        'mc' => $mc,
        'user' => $user
    ));
});

// *************************************************************************
// Search Result Page

$app->match('/search', function (Request $request) use ($app) {
    $form = $app['form.factory']->createBuilder('form')
        ->add('search', 'text', array(
            'label' => 'Search',
            'constraints' => array(new Assert\NotBlank())
        ))
        ->getForm();
    $form->handleRequest($request);
    if ($form->isValid()) {
        $regform = $form->getData();
		$srch = $regform['search'];
		
		// Create prepared query 
        $db = $app['db'];
		$query = "SELECT showID, title FROM shows WHERE title LIKE ?";
		$shows = queryDB($db, $query, array('%'.$srch.'%'));

		$query = "(SELECT actorID, fname, lname FROM actors WHERE lname LIKE ?)
				UNION (SELECT actorID, fname, lname FROM actors WHERE fname LIKE ?)";
		$actors = queryDB($db, $query, array('%'.$srch.'%','%'.$srch.'%'));

        // Display results in search page
        return $app['twig']->render('search.html.twig', array(
            'pageTitle' => 'Search',
            'form' => $form->createView(),
            'shows' => $shows,
            'actors' => $actors
        ));
    }
    // If search box is empty, redisplay search page
    return $app['twig']->render('search.html.twig', array(
        'pageTitle' => 'Search',
        'form' => $form->createView(),
        'shows' => '',
        'actors' => ''
    ));
});

// *************************************************************************
// Enqueue
$app ->get('/enqueue/{showID}', function (Silex\Application $app, $showID) {
	$db = $app['db'];
	
	//get custID and user information from sessions variables
	if ($app['session']->get('is_user')) {
		$user = $app['session']->get('user');
		$custID = $app['session']->get('custID');
	}else{
		$user = '';
	}
	
	$datequeued = date("Y-m-d"); //date queued is current date
	
	$insertData = array($custID, $showID, $datequeued);
	
	$query = 'insert into cust_queue 
        		(custID, showID, datequeued)
        		values (?, ?, ?)';
        $results = queryDB($db, $query, $insertData);
        	
	//Go to home page
        return $app->redirect('/streamtv/');
 
    return $app['twig']->render('form.html.twig', array(
        'pageTitle' => 'Enqueue',
        'form' => $form->createView(),
        'results' => ''
    ));   
});

// *************************************************************************
// Dequeue
$app ->get('/dequeue/{showID}', function (Silex\Application $app, $showID) {
	$db = $app['db'];
	
	//get custID and user information from sessions variables
	if ($app['session']->get('is_user')) {
		$user = $app['session']->get('user');
		$custID = $app['session']->get('custID');
	}else{
		$user = '';
	}
	
	$deleteData = array($custID, $showID);
	
	//delete data in WATCHED
	//because it depends on cust_queue
//!!!!!!!!!!!!!!!!!THIS CAN BE ABUSED BUG IN PROGRAM!!!!!!!!!!!!!!!!!!!!
	//user can delete show from queue and then watch episode again
		//to fix relational db has to change
	$query = 'DELETE FROM watched
			WHERE custID = ? AND
				showID = ?';
	$deleted = queryDB($db, $query, $deleteData);
	
	//remove from queue
	$query = 'DELETE FROM cust_queue
			WHERE custID = ? AND
				showID = ?';
        $results = queryDB($db, $query, $deleteData);
        	
	//Go to home page
        return $app->redirect('/streamtv/');
 
    return $app['twig']->render('form.html.twig', array(
        'pageTitle' => 'Dequeue',
        'form' => $form->createView(),
        'results' => ''
    ));   
});

// *************************************************************************

// Queued

$app->match('/queue', function() use ($app) {
	// Get session variables
	$queued = '';

	if ($app['session']->get('is_user')) {
		$user = $app['session']->get('user');
		$custID = $app['session']->get('custID');
		
		
	        $db = $app['db'];
	        
		// Look up customer's queue
		$query = "SELECT s.title, s.showID, c.fname, c.lname, c.email, q.datequeued, c.custID
				FROM shows s, customer c, cust_queue q 
				WHERE s.showID = q.showID AND
					c.custID = q.custID AND
					c.custID = ?";
					
		$queued = queryDB($db, $query, array($custID));

	}
	
	//send to twig
	return $app['twig']->render('queue.html.twig', array(
		'pageTitle' => 'Queued',
		'queued' => $queued
	));
});

// *************************************************************************
// watch_episode
$app ->get('/watched/{showID}', function (Silex\Application $app, $showID) {
	$db = $app['db'];
	
	$result = '';
	//get custID and user information from sessions variables
	if ($app['session']->get('is_user')) {
		$user = $app['session']->get('user');
		$custID = $app['session']->get('custID');
		
	$query = "SELECT s.showID, s.title AS stitle, c.fname, c.lname, e.episodeID, e.title AS etitle, max(w.datewatched) AS datewatched
	 		FROM shows s, customer c, episode e, watched w 
			WHERE w.custID = c.custID AND
				w.showID = s.showID AND
				w.episodeID = e.episodeID AND
				e.showID = s.showID AND
				s.showID = ? AND c.custID = ? 
				GROUP BY e.episodeID 
				ORDER BY w.datewatched";
	$result = queryDB($db, $query, array($showID,$custID));
	
	}
	return $app['twig']->render('watched.html.twig', array(
		'pageTitle' =>'Watched Info',
		'result' =>$result
	));
});

// *************************************************************************
// Watching Page
$app ->get('/watch_episode/{showID}&{episodeID}', function (Silex\Application $app, $showID, $episodeID) {
	$db = $app['db'];
	$result = '';
	//get custID and user information from sessions variables
	if ($app['session']->get('is_user')) {
		$user = $app['session']->get('user');
		$custID = $app['session']->get('custID');
	}
	
	//last time episode was watched
	$query = "select max(w.datewatched) from watched w
	where w.showID = ? and w.episodeID = ? and w.custID = ?";
	
	
	$datewatched = queryDB($db,$query, array($showID, $episodeID, $custID));
	$datewatched = $datewatched[0][0]; // extract date value
	
	$currdate = date("Y-m-d"); //current date
	
	if($currdate != $datewatched){ //compare current date and query result
	
		$insertData = array($custID, $currdate, $episodeID, $showID);
       		$query = 'insert into watched 
        		(custID, datewatched, episodeID, showID)
        			values (?, ?, ?, ?)';
        	$result = queryDB($db, $query, $insertData);
  
	}
	
	$query = "SELECT s.title AS stitle, e.title AS etitle 
			FROM shows s, episode e 
			WHERE e.showID = s.showID AND
			s.showID = ? AND e.episodeID = ?";
			
	$info = queryDB($db,$query, array($showID, $episodeID));
	     
	     

        return $app['twig']->render('watch_episode.html.twig', array(
		'pageTitle' =>'Watching',
		'result' =>$result,
		'info' =>$info
		
	));
});

// *************************************************************************

// Delete Account
$app ->get('/delete_account/{showID}', function (Silex\Application $app, $showID) {
	$db = $app['db'];
	
	//get custID and user information from sessions variables
	if ($app['session']->get('is_user')) {
		$user = $app['session']->get('user');
		$custID = $app['session']->get('custID');
	}
	
	//delete watched
	$query = 'DELETE FROM watched
			WHERE custID = ?';
	$deleted = queryDB($db, $query, array($custID));
	
	$query = 'DELETE FROM cust_queue
			WHERE custID = ?';
	
	$deleted = queryDB($db, $query, array($custID));    
	     
	
	$query = 'DELETE FROM customer
			WHERE custID = ?';
	
	$deleted = queryDB($db, $query, array($custID));
	
	$app['session']->clear();
	return $app->redirect('/streamtv/');
        return $app['twig']->render('form.html.twig', array(
		'form' => $form->createView()
		
	));
});

		
// *************************************************************************

// Logout

$app->get('/logout', function () use ($app) {
	$app['session']->clear();
	return $app->redirect('/streamtv/');
});
	
// *************************************************************************

// Home Page

$app->get('/', function () use ($app) {
	if ($app['session']->get('is_user')) {
		$user = $app['session']->get('user');
	}
	else {
		$user = '';
	}
	return $app['twig']->render('home.twig', array(
        'user' => $user,
        'pageTitle' => 'Home'));
});

// *************************************************************************

// Run the Application

$app->run();