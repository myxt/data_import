<?php

class PubmedHandler extends SourceHandler
{
    public $first_employee = true;
    public $first_row = true;
    public $first_field = true;
    public $current_employee;

    // Caches
    public $sourceIdCache = array();
    public $employeeSearchCache = array();

    public $idPrepend = 'pubmed_';
    public $handlerTitle = 'Pubmed Publications Handler';

    public $current_loc_info = array();
    public $logfile = 'pubmed_import.log';
    public $remoteID = '';
    public $classIdentifier = 'publication';
    public $parentNodeID = 2;
    public $year;

    protected $API;

    const REMOTE_IDENTIFIER = 'pubmed_';

    public function __construct()
    {
        $this->API = new PubMedAPI();
        $this->API->retmax = 100;
        $this->API->exact_match = false;
    }

    public function init( array $parameters )
    {
        $ini = eZINI::instance('data_import.ini');
        $this->parameters = $parameters;

        if( $ini->hasVariable( 'PubmedHandler', 'ClassIdentifier' ) )
            $this->classIdentifier = $ini->variable( 'PubmedHandler', 'ClassIdentifier' );
        if( $ini->hasVariable( 'PubmedHandler', 'ParentNodeID' ) )
            $this->parentNodeID = $ini->variable( 'PubmedHandler', 'ParentNodeID' );

        $this->year = isset( $this->parameters['year'] ) ? $this->parameters['year'] : date('Y');
        return $this;
    }

    public function writeLog( $message, $newlogfile = '')
    {
        if($newlogfile)
            $logfile = $newlogfile;
        else
            $logfile = $this->logfile;
        
        $this->logger->write( self::REMOTE_IDENTIFIER.$this->current_row->getAttribute('id').': '.$message , $logfile );
    }
    
    public function getParentNodeId()
    {
        return $this->parentNodeID;
    }

    public function getDataRowId()
    {
        return self::REMOTE_IDENTIFIER.$this->current_row['pmid'];
    }

    public function getTargetContentClass()
    {
        return $this->classIdentifier;
    }

    public function readData( $employees )
    {
        $data = array();
        $idArray = array();

        foreach( $employees as $employee )
        {

            $map = $employee->attribute('data_map');

            // Only include visible employees
            $node = $employee->attribute('main_node');
            if( !$node )
                continue;
            if( $employee->attribute('main_node')->attribute('is_invisible') == 1 || 
                $employee->attribute('main_node')->attribute('is_hidden') == 1 )
                continue;

            // Only include of these types
            $types = $map['type']->attribute('content');
            echo $employee->Name . " (" . $types[0] ."); ";
            if( $types[0] !== 'arts' && $types[0] !== 'artsass' && $types[0] !== 'arts-assistent' && $types[0] !== 'overig' )
                continue;

            if( $map['publications_link']->attribute('has_content') &&
                strpos( $map['publications_link']->attribute('content'), 'pubmed') !== false )
            {
                $queryType = 'link';
                $searchTerm = $this->linkBasedSearchTerm( $employee );
            } else {
		continue;
	    }
/*  Op verzoek van Janneke eruit gehaald. Alleen nog zoeken op basis van in profielen ingevoerde zoekopdrachten.
			         else
            {
                $queryType = 'name';
                $searchTerm = $this->nameBasedSearchTerm( $employee );
            }
*/
            $searchParams = array( 'mindate' => $this->year,
                                   'maxdate' => $this->year );

            $results = $this->API->query( $searchTerm, $searchParams );

            // Bouwt een array van publicatie id's uit de geimporteerde data,
            // nodig om ontbrekende publicaties uit eZ te verwijderen.
            // Voegt eventuele nieuwe attributen toe.
            foreach( $results as $key => $result )
            {
                $idArray[] = $result['pmid'];
                $results[$key]['employees'] = '';
                $results[$key]['departments'] = '';
            }

            $data[$employee->ID] = array(
                'queryType' => $queryType,
                'object' => $employee,
                'results' => $results
            );

        }
        $this->data = $data;
        $this->sourceIdCache = array_merge( $this->sourceIdCache, $idArray );
    }

    public function getNextEmployee()
    {
        $this->first_row = true;

        if( $this->first_employee )
        {
            $this->first_employee = false;
            $this->current_employee = current( $this->data );
        }
        else
        {
            $this->current_employee = next( $this->data );
        }
        return $this->current_employee;
        
    }

    public function getNextRow()
    {
        $this->first_field = true;
        $this->node_priority = false;
        
        if( $this->first_row )
        {
            $this->first_row = false;
            //reset($this->current_row); 
            $this->current_row = prev( $this->data[$this->current_employee['object']->ID]['results'] );
        }
        else
        {
            $this->current_row = next( $this->data[$this->current_employee['object']->ID]['results'] );
        }

        //if($this->current_row['pmid'] == 8455427)
        // 111 print_r($this->current_row);
        return $this->current_row;
    }

    public function getNextField()
    {
        $name = $value = false;
        if( $this->first_field )
        {
            $this->first_field = false;
            $value = current( $this->current_row );
            $name = key( $this->current_row );
        }
        else
        {
            $value = next( $this->current_row );
            $name = key( $this->current_row );
        }

        if( $name )
        {
            $this->current_field = array( 'name' => $name,
                                          'value' => $value );
        }
        else
        {
            $this->current_field = false;
        }
        return $this->current_field;
    }

    public function getValueFromField( eZContentObjectAttribute $contentObjectAttribute )
    {
        //if( $contentObjectAttribute->ContentClassAttributeIdentifier == 'title' )
        // 000 print_r($this->current_field['value']);
        
        switch ( $contentObjectAttribute->ContentClassAttributeIdentifier ) {

            case 'authors':
                //$employeeName = $this->formatName( $this->current_employee['object'] );
                $authors = $this->current_row['authors'];
                //foreach( $authors as $key => $author ) {
                //    if( trim( $author ) == "" ) unset( $authors[$key] );
                //}
                $authors = implode( ", ", $authors );
                //$authors = str_replace( $employeeName, "<a href='" . MyxtLinkOperators::prefixUrl( $this->current_employee['object']->attribute('main_node')->attribute('url_alias') ) . "'>" . $employeeName . "</a>", $authors );
                //echo $authors;
                return $authors;                

            case 'employees': // object relation list
                $content = $contentObjectAttribute->content();
                $newContent = Array();
                $new = true;
                
                foreach( $content['relation_list'] as $relation ) {
                    if( $relation['contentobject_id'] == $this->current_employee['object']->ID )
                        $new = false;
                    $newContent[] = $relation['contentobject_id'];
                }

                // If we have more that one OLVG author in this publication, and this is a new author.
                if( $new ) {
                    $newContent[] = $this->current_employee['object']->ID;
                }

                return implode( '-', $newContent );

            case 'departments':
                $content = $contentObjectAttribute->content();
                $newContent = Array();
                $new = true;

                $employeeNode = $this->current_employee['object']->attribute('main_node');
                $parentNode = $employeeNode->attribute('parent');
                $counter = 1;
                while( $parentNode->attribute('class_identifier') !== 'afdeling' && $counter < 10 ) {
                    $parentNode = $parentNode->attribute('parent');
                    $counter++;
                }
                
                foreach( $content['relation_list'] as $relation ) {
                    if( $relation['contentobject_id'] == $parentNode->attribute('contentobject_id') )
                        $new = false;
                    $newContent[] = $relation['contentobject_id'];
                }

                // If we have more that one OLVG author in this publication, and this is a new author.
                if( $new && $parentNode->attribute('class_identifier') == 'afdeling' ) {
                    $newContent[] = $parentNode->attribute('contentobject_id');
                }

                return implode( '-', $newContent );

            default:
                return $this->current_field['value'];
        }

    }

    private function linkBasedSearchTerm( $employee )
    {
        $map = $employee->attribute('data_map');
        $publicationLinks = $map['publications_link']->attribute('content');
        if( !strpos( $publicationLinks, 'term=' ) )
            $publicationLinks = str_replace( '/pubmed/', '/pubmed?term=', $publicationLinks );
        $urlParts = parse_url( $publicationLinks );
        parse_str( $urlParts['query'] );
        return $term;
    }

    private function nameBasedSearchTerm( $employee, $delimiter = "+" )
    {
        $map = $employee->attribute('data_map');

        $insertion = trim( $map['insertion']->attribute('content') );
        $lastName = trim( $map['last_name']->attribute('content') );
        if( $insertion ) $lastName = $insertion . $delimiter . $lastName;
        $initials = str_replace( array( ".", " " ), "", trim( $map['initials']->attribute('content') ) );
        return $lastName . " " . $initials;
    }

    public function formatName( $employee ) {
        return $this->nameBasedSearchTerm( $employee, " " );
    }

    public function isExistingEmployee( $name ) {
        $name = strtolower( $name );
//print_r($this->employeeSearchCache);
        // Check cache if we already queried for this employee.
        if( isset( $this->employeeSearchCache[$name] ) )
            return $this->employeeSearchCache[$name];

        $result = eZSearch::search( $name, array( 'SearchContentClassID' => array( 20 ) ) );
        if( isset( $result['SearchResult'][0] ) ) {
            $this->employeeSearchCache[$name] = 1;
            return true;
        }
        else {
            $this->employeeSearchCache[$name] = 0;
            return false;
        }
    }

    public function geteZAttributeIdentifierFromField()
    {
        return $this->current_field['name'];
    }

    public function post_publish_handling( $eZ_object, $force_exit )
    {
        $force_exit = false;
        return true;
    }

}

?>
