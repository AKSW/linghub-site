<?php
/**
 * Controller for OntoWiki Navigation Module
 *
 * @category   OntoWiki
 * @package    extensions_components_navigation
 * @author     Sebastian Tramp <tramp@informatik.uni-leipzig.de>
 * @copyright  Copyright (c) 2009, {@link http://aksw.org AKSW}
 * @license    http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */
 
require_once 'Erfurt/Sparql/Query2.php';

class NavigationController extends OntoWiki_Controller_Component
{
    private $store;
    private $translate;
    private $session;
    private $ac;
    private $model;
    /* an array of arrays, each has type and text */
    private $messages = array();
    /* the setup consists of state and config */
    private $setup = null;
    private $limit = 50;

    /*
     * Initializes Naviagation Controller,
     * creates class vars for current store, session and model
     */
    public function init()
    {
        parent::init();
        $this->store = $this->_owApp->erfurt->getStore();
        $this->translate = $this->_owApp->translate;
        $this->session = $this->_owApp->session->navigation;
        $this->ac = $this->_erfurt->getAc();
        
        $sessionKey = 'Navigation' . (isset($config->session->identifier) ? $config->session->identifier : '');        
        $this->stateSession = new Zend_Session_Namespace($sessionKey);

        $this->model = $this->_owApp->selectedModel;
        if (isset($this->_request->m)) {
            $this->model = $store->getModel($this->_request->m);
        }
        if (empty($this->model)) {
            throw new OntoWiki_Exception('Missing parameter m (model) and no selected model in session!');
            exit;
        }
        // Model Based Access Control
        if (!$this->ac->isModelAllowed('view', $this->model->getModelIri()) ) {
            throw new Erfurt_Ac_Exception('You are not allowed to read this model.');
        }
    }

    /*
     * The main action which is retrieved via ajax
     */
    public function exploreAction() {
        // disable standart navigation
        OntoWiki_Navigation::disableNavigation();
        // log action
        $this->_owApp->logger->info('NavigationController Stage 1');
        // translate navigation title to selected language
        $this->view->placeholder('main.window.title')
            ->set($this->translate->_('Navigation'));

        // check if setup is present
        if (empty($this->_request->setup)) {
            throw new OntoWiki_Exception('Missing parameter setup !');
            exit;
        }
        // decode setup from JSON into array
        $this->setup = json_decode($this->_request->getParam('setup'));

        // check if setup was not converted
        if ($this->setup == false) {
            throw new OntoWiki_Exception('Invalid parameter setup (json_decode failed): ' . $this->_request->setup);
            exit;
        }

        // overwrite the hard limit with the given one
        if (isset($this->setup->state->limit)) {
            $this->limit = $this->setup->state->limit;
        }

        // build initial view
        $this->view->entries = $this->_queryNavigationEntries($this->setup);

        // if lastEvent was "show me more" do not show root element
        if( $this->setup->state->lastEvent == 'more' ){
            $this->view->showRoot = false;
        }
        
        // set view variable for the show more button
        if ( (count($this->view->entries) > $this->limit) && $this->setup->state->lastEvent != "search") {
            // return only $limit entries
            $this->view->entries = array_slice($this->view->entries, 0, $this->limit);
            $this->view->showMeMore = true;
        } else {
            $this->view->showMeMore = false;
        }

        // if there's no entries, show text
        if (empty($this->view->entries)) {
            if (isset($this->setup->state->searchString)) {
                $this->messages[] = array( 'type' => 'info', 'text' => 'No result for this search.');
            } else {
                $this->messages[] = array( 'type' => 'info', 'text' => 'Nothing to navigate here.');
            }
        }

        // the root entry (parent of the shown elements)
        if ( isset($this->setup->state->parent) ) {
            $this->view->rootEntry = array();
            $this->view->rootEntry['uri'] = $this->_getListLink($this->setup->state->parent, $this->setup);
            $this->view->rootEntry['title'] = $this->_getTitle(
                $this->setup->state->parent,
                isset($this->setup->config->titleMode) ? $this->setup->config->titleMode : null,
                null
            );
        }

        // if search string is set, show it in view
        if (isset($this->setup->state->searchString)) {
            $this->view->searchString = $this->setup->state->searchString;
        }

        // if rootName is set, show it in view
        if( isset($this->setup->config->rootName) ){
            $this->view->rootName = $this->setup->config->rootName;
        }

        // if rootURI is set, apply it to rootName in view
        if( isset($this->setup->config->rootURI) ){
            $this->view->rootLink = $this->_getListLink($this->setup->config->rootURI, $this->setup);
        }

        // set view messages and setup
        $this->view->messages = $this->messages;
        $this->view->setup = $this->setup;

        // save state to session
        $this->savestateServer($this->view, $this->setup);

        return;
    }

    /*
     * Saves current view, setup and model to state to use it on refresh
     */
    protected function savestateServer($view, $setup){
        // encode setup to json
        $setup = json_encode($setup);
        // replace \' and \" to ' and "
        $replaceFrom = array("\\'", '\\"');
        $replaceTo = array("'", '"');
        $setup = str_replace($replaceFrom, $replaceTo, $setup);

        // save view, setup and current model to state
        $this->stateSession->view = $view->render("navigation/explore.phtml");
        $this->stateSession->setup = $setup;
        $this->stateSession->model = (string)$this->model;
    }

    /*
     * Queries all navigation entries according to a given setup
     */
    protected function _queryNavigationEntries($setup) {
        /*$this->_owApp->logger->info(
            'NavigationController _queryNavigationEntries Input: ' .PHP_EOL . print_r($setup,true)
        );*/
        // if user searched for something
        if( $setup->state->lastEvent == "search" ){
            // search request
            // @todo: also search request should not show ignored entities
            $resVar = new Erfurt_Sparql_Query2_Var('resourceUri');
            $typeVar = new Erfurt_Sparql_Query2_IriRef(EF_RDF_TYPE);
            
            $query = new Erfurt_Sparql_Query2();
            $query->addProjectionVar($resVar)->setDistinct(true);

            $pattern = $this->store->getSearchPattern($setup->state->searchString, (string) $this->model);
            $query->addElements($pattern);
            
            $union = new Erfurt_Sparql_Query2_GroupOrUnionGraphPattern();
            
            foreach ($setup->config->hierarchyTypes as $type) {
                $u1 = new Erfurt_Sparql_Query2_GroupGraphPattern();
                $u1->addTriple( $resVar,
                    $typeVar,
                    new Erfurt_Sparql_Query2_IriRef($type)
                );
                $union->addElement($u1);
            }
            $query->addElement($union);
            
            // set to limit+1, so we can see if there are more than $limit entries
            $query->setLimit($this->limit + 1);
            
        } else {
            // if there's no top query assigned and hideDefaultHierarchy is false
            // generate query based on setup
            if ( ( !isset($setup->config->hideDefaultHierarchy) || $setup->config->hideDefaultHierarchy == false )
                    && !isset($setup->config->query->top) ){
                $query = $this->_buildQuery($setup, false);
            }else if( isset($setup->config->query->top) ){
                // if top query is assigned - use it
                $query = Erfurt_Sparql_SimpleQuery::initWithString($setup->config->query->top);
            }else{
                $query = null;
            }
        }

        // if there's something wrong with query generation - exit
        if($query == null) return;
        
        // error logging
        $this->_owApp->logger->info(
            'NavigationController _queryNavigationEntries Query: ' .$query->__toString()
        );

        // get results of query
        $results = $this->model->sparqlQuery($query);

        // check if we need to show implicit elements
        $showImplicit = false;
        if( !isset($setup->config->rootElement) ){
            if(!isset($setup->state->showImplicit)){
                if(isset($setup->config->showImplicitElements) && $setup->config->showImplicitElements == true ){
                    $showImplicit = true;
                }
            }else{ 
                if($setup->state->showImplicit == true){
                    $showImplicit = true;
                }
            }
        }

        // if we need to show implicit elements
        // generate additional query for them
        if($showImplicit){
            // new query for regular event
            if($setup->state->lastEvent != "search"){
                $query = $this->_buildQuery($setup, true);
                $results_implicit = $this->model->sparqlQuery($query);
            }else{
                // new query for search
                $query = $this->_buildStringSearchQuery($setup);
                $results_implicit = $this->model->sparqlQuery($query);
            }
            
            // append implicit classes to results
            foreach($results_implicit as $res){
                if( !in_array($res, $results) ){
                    $results[] = $res;
                }
            }
        }
            
        // log results
        /*$this->_owApp->logger->info(
            'NavigationController _queryNavigationEntries Result: '  . PHP_EOL . print_r($results,true)
        );*/

        // set titleMode from config or set it to null if config is not assigned
        if ( isset($setup->config->titleMode) ){
            $mode = $setup->config->titleMode;
        } else {
            $mode = null;
        }

        // if title mode set to titlehelper
        // get titles of all resources
        if ($mode == "titleHelper"){
            //$this->_owApp->logger->info('TITLE HELPER.');
            //$this->_owApp->logger->info('TITLE HELPER REs: '.print_r($results,true));
            $this->titleHelper = new OntoWiki_Model_TitleHelper($this->model);
            if (isset($results)){
                foreach ($results as $result) {
                    //$this->_owApp->logger->info('TITLE HELPER: '.$result['resourceUri']);
                    //$this->_owApp->logger->info('TITLE HELPER: '.$result['subResourceUri']);
                    $this->titleHelper->addResource($result['resourceUri']);
                }
            }
            // add parent to titleHelper to get title
            if( isset($setup->state->parent) ){
                $this->titleHelper->addResource($setup->state->parent);
            }
        }

        // create new array for navigation entries
        $entries = array();

        // if there's no query results - exit
        if ($results == null) return;

        // parse all results to entries
        foreach ($results as $result) {
            $entry = array();

            // assing resource URI
            $uri = $result['resourceUri'];
            // get resource title
            $entry['title'] = $this->_getTitle($uri, $mode, $setup);
            // get resource ling
            $entry['link'] = $this->_getListLink($uri, $setup);

            // chech if there's need to look for subresources
            $checkSubs = false;
            if(isset($setup->config->checkSub) && $setup->config->checkSub == true ){
                $checkSubs = true;
            }
            // if needed look for subresources
            if($checkSubs){
                // build sub query
                $query = $this->_buildSubCheckQuery($uri, $setup);
                // get results
                $results = $this->model->sparqlQuery($query);
                // assigh count of results
                $entry['sub'] = count($results);
            }else{
                // if there's no need to look for subres
                // just set var to 1, so that "go deeper" arrow
                // will be allways visible
                $entry['sub'] = 1;
            }
            
            // check if filtering empty is enabled
            $filterEmpty = false;
            if(!isset($setup->state->showEmpty)){
                if(isset($setup->config->showEmptyElements) && $setup->config->showEmptyElements == false ){
                    $filterEmpty = true;
                }
            }else{ 
                if($setup->state->showEmpty == false){
                    $filterEmpty = true;
                }
            }

            // do filter empty if needed
            $show = true;
            if( $filterEmpty ){
                // generate query
                $query = $this->_buildCountQuery($uri, $setup);
                // get results
                $results = $this->model->sparqlQuery($query);
                // depending on result format set count
                if( isset($results[0]['callret-0']) ){
                    $count = $results[0]['callret-0'];
                }else{
                    $count = count($results);
                }
                // if count is 0 do not show entry
                if($count == 0) $show = false;
            }
            // apply $show flag
            if($show) $entries[$uri] = $entry;
        }

        //$this->_owApp->logger->info('ENTRIES: '.print_r($entries,true));

        return $entries;
    }

    /*
     * Gets title for given $uri based on $mode and $setup
     */
    protected function _getTitle($uri, $mode, $setup){
        $name = '';
        // set default mode if none is set
        if (!isset($mode) || $mode == null) $mode = "baseName";

        // get title
        if ($mode == "titleHelper") {
            $name = $this->titleHelper->getTitle($uri);
        } elseif($mode == "baseName"){
            if (strrpos($uri, '#') > 0) {
                $name = substr($uri, strrpos($uri, '#')+1);
            } elseif (strrpos($uri, '/') > 0) {
                $name = substr($uri, strrpos($uri, '/')+1);
            } else {
                $name = $uri;
            }
        } else {
            $name = 'error';
        }
        
        // count entries
        if( isset($setup->config->showCounts) && $setup->config->showCounts == true ){               
            $query = $this->_buildCountQuery($uri, $setup);
            $results = $this->model->sparqlQuery($query);
            if( isset($results[0]['callret-0']) ){
                $count = $results[0]['callret-0'];
            }else{
                $count = count($results);
            }
                        
            if( $count > 0 ) $name .= ' ('.$count.')';
        }
        
        return $name;
    }

    /*
     * Builds query for main actions (root, nav. deeper)
     */
    protected function _buildQuery($setup, $forImplicit = false){
        if( isset($setup->config->query->deeper) && isset($setup->state->parent) ){
            //$replace = ;
            $query_string = str_replace("%resource%", $setup->state->parent, $setup->config->query->deeper);
            $query = Erfurt_Sparql_SimpleQuery::initWithString($query_string);
        }else{
            $query = new Erfurt_Sparql_Query2();
            $query->addElements(NavigationHelper::getSearchTriples($setup, $forImplicit));
            //$query->setCountStar(true);
            $query->setDistinct(true);
            $query->addProjectionVar(new Erfurt_Sparql_Query2_Var('resourceUri'));
            //$query->addProjectionVar(new Erfurt_Sparql_Query2_Var('subResourceUri'));
            // set to limit+1, so we can see if there are more than $limit entries
            $query->setLimit($this->limit + 1);
        }
        // set ordering
        if( isset($setup->config->ordering->relation) ){
            $query->getOrder()->add(
                new Erfurt_Sparql_Query2_IriRef($setup->config->ordering->relation),
                $setup->config->ordering->modifier
            );
        }
        // set offset
        if( isset($setup->state->offset) && $setup->state->lastEvent == 'more' ){
            $query->setOffset($setup->state->offset);
        }
        
        return $query;
    }

    /*
     * Builds search query string
     */
    protected function _buildStringSearchQuery($setup){
        // define vars
        $searchVar = new Erfurt_Sparql_Query2_Var('resourceUri');
        $subVar = new Erfurt_Sparql_Query2_Var('sub');
        
        // define query
        $query = new Erfurt_Sparql_Query2();
        $query->addProjectionVar($searchVar);
        $query->setDistinct();
        
        // init union var
        $union = new Erfurt_Sparql_Query2_GroupOrUnionGraphPattern();
        // parse config
        if( isset($setup->config->instanceRelation->out) ){
            foreach($setup->config->instanceRelation->out as $rel){
                // create new graph pattern
                $u1 = new Erfurt_Sparql_Query2_GroupGraphPattern();
                // add triplen
                $u1->addTriple( $subVar,
                    new Erfurt_Sparql_Query2_IriRef($rel),//EF_RDF_TYPE),
                    $searchVar
                );
                // add triplet to union var
                $union->addElement($u1);
            }
        }
        // parse config
        if( isset($setup->config->instanceRelation->in) ){
            foreach($setup->config->instanceRelation->in as $rel){
                // create new graph pattern
                $u1 = new Erfurt_Sparql_Query2_GroupGraphPattern();
                // add triplen
                $u1->addTriple( $searchVar,
                    new Erfurt_Sparql_Query2_IriRef($rel),//EF_RDF_TYPE),
                    $subVar
                );
                // add triplet to union var
                $union->addElement($u1);
            }
        }
        $query->addElement($union);
        // add regex filter for search string
        $query->addFilter(
            new Erfurt_Sparql_Query2_Regex(
                new Erfurt_Sparql_Query2_Str( $searchVar ),
                new Erfurt_Sparql_Query2_RDFLiteral($setup->state->searchString)
            )
        );

        return $query;
    }

    /*
     * Builds counting query for given $uri
     */
    protected function _buildCountQuery($uri, $setup){
        $query = new Erfurt_Sparql_Query2();
        $query->addProjectionVar(new Erfurt_Sparql_Query2_Var('resourceUri'));
        $query->setCountStar(true);
        $query->setDistinct();
        $query->addElements(NavigationHelper::getInstancesTriples($uri, $setup));
        
        return $query;
    }

    /*
     * Builds query to check for subresources for $uri based on $setup
     */
    protected function _buildSubCheckQuery($uri, $setup){
        $subVar = new Erfurt_Sparql_Query2_Var('subResourceUri');
        $searchVar = new Erfurt_Sparql_Query2_Var('resourceUri');
        $classVar = new Erfurt_Sparql_Query2_Var('classUri');
        $query = new Erfurt_Sparql_Query2();
        $query->addProjectionVar($subVar);
        $query->setDistinct();

        //$this->_owApp->logger->info("data: ".print_r($query,true));
        $elements = array();
        
        if ( isset($setup->config->hierarchyRelations->in) ){
            if( count($setup->config->hierarchyRelations->in) > 1 ){
                // init union var
                $unionSub = new Erfurt_Sparql_Query2_GroupOrUnionGraphPattern();
                // parse config gile
                foreach($setup->config->hierarchyRelations->in as $rel){
                    // sub stuff
                    $u1 = new Erfurt_Sparql_Query2_GroupGraphPattern();
                    // add triplen
                    $u1->addTriple(
                        $subVar,
                        new Erfurt_Sparql_Query2_IriRef($rel),
                        $searchVar
                    );
                    // add triplet to union var
                    $unionSub->addElement($u1);
                }
                $elements[] = $unionSub;
            }else{
                $rel = $setup->config->hierarchyRelations->in;
                // add optional sub relation
                // create optional graph to load sublacsses of selected class
                $queryOptional = new Erfurt_Sparql_Query2_GroupGraphPattern();
                $queryOptional->addTriple(
                    $subVar,
                    new Erfurt_Sparql_Query2_IriRef($rel[0]),
                    $searchVar
                );
                $elements[] = $queryOptional;
            }
        }
        if ( isset($setup->config->hierarchyRelations->out) ){
            if( count($setup->config->hierarchyRelations->out) > 1 ){
                // init union var
                $unionSub = new Erfurt_Sparql_Query2_GroupGraphPattern();
                // parse config gile
                foreach($setup->config->hierarchyRelations->out as $rel){
                    // sub stuff
                    $u1 = new Erfurt_Sparql_Query2_OptionalGraphPattern();
                    // add triplen
                    $u1->addTriple(
                        $searchVar,
                        new Erfurt_Sparql_Query2_IriRef($rel),
                        $subVar
                    );
                    // add triplet to union var
                    $unionSub->addElement($u1);
                }
                $elements[] = $unionSub;
            }else{
                $rel = $setup->config->hierarchyRelations->out;
                // add optional sub relation
                // create optional graph to load sublacsses of selected class
                $queryOptional = new Erfurt_Sparql_Query2_GroupGraphPattern();
                $queryOptional->addTriple(
                    $searchVar,
                    new Erfurt_Sparql_Query2_IriRef($rel[0]),
                    $subVar
                );
                $elements[] = $queryOptional;
            }
        }
        $elements[] = new Erfurt_Sparql_Query2_Triple(
            $searchVar,
            new Erfurt_Sparql_Query2_IriRef(EF_RDF_TYPE),
            $classVar
        );
        // add filter
        $elements[] = new Erfurt_Sparql_Query2_Filter(
            new Erfurt_Sparql_Query2_sameTerm($searchVar, new Erfurt_Sparql_Query2_IriRef($uri))
        );
        $query->addElements($elements);
        $query->setLimit(1);

        return $query;
    }

    /*
     * This method returns the link to the resource list action
     * according to a given URI in the navigation module and a
     * given navigation setup
     */
    protected function _getListLink ($uri, $setup) {
        $owUrl = new OntoWiki_Url(array('route' => 'instances'), array());
        $return = (string) $owUrl;

        // at the moment, we use r= here, not class=
        $return .= "?init";
        $conf = array();
        // there is a shortcut for rdfs classes
        if ( !empty($setup->config->instanceRelation->out) && !empty($setup->config->instanceRelation->in) ) {
            if( ( $setup->config->instanceRelation->out[0] == EF_RDF_TYPE) &&
                    ($setup->config->hierarchyRelations->in[0] == EF_RDFS_SUBCLASSOF ) ){
                $conf['filter'][] = array(
                    'mode' => 'rdfsclass',
                    'rdfsclass' => $uri,
                    'action' => 'add'
                );
            }else{
                foreach($setup->config->instanceRelation->out as $out){
                    $conf['filter'][] = array(
                        'mode' => 'box',
                        'action' => 'add',
                        'id' => 'box1',
                        'property' => $out,
                        'value' => $uri
                    );
                }
                foreach($setup->config->hierarchyRelations->in as $in){
                    $conf['filter'][] = array(
                        'mode' => 'box',
                        'action' => 'add',
                        'id' => 'box2',
                        'property' => $uri,
                        'value' => $in
                    );
                }
            }
            return $return . "&instancesconfig=".urlencode(json_encode($conf));
        } else {
            $conf['filter'][] = array(
                'mode' => 'cnav',
                'cnav' => $setup,
                'uri'  => $uri,
                'action' => 'add'
            );
            return $return . "&instancesconfig=" . urlencode(json_encode($conf));
        }
    }

}
