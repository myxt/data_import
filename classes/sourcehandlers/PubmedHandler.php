<?php

class PubmedHandler extends SourceHandler
{
    public $first_employee = true;
    public $first_row = true;
    public $first_field = true;
    public $current_employee;

    public $sourceIdArray = array();

    public $idPrepend = 'pubmed_';
    public $handlerTitle = 'Pubmed Publications Handler';

    public $current_loc_info = array();
    public $logfile = 'images_import.log';
    public $remoteID = '';
    public $classIdentifier = 'publication';
    public $parentNodeID = 2;

    protected $API;

    const REMOTE_IDENTIFIER = 'pubmed_';

    public function __construct()
    {
        $ini = eZINI::instance('data_import.ini');
        if( $ini->hasVariable( 'PubmedHandler', 'ClassIdentifier' ) )
            $this->classIdentifier = $ini->variable( 'PubmedHandler', 'ClassIdentifier' );
        if( $ini->hasVariable( 'PubmedHandler', 'ParentNodeID' ) )
            $this->parentNodeID = $ini->variable( 'PubmedHandler', 'ParentNodeID' );

        $this->API = new PubMedAPI();
        $this->API->retmax = 100;
        $this->API->exact_match = false;
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
            if( $map['publications_link']->attribute('has_content') )
            {
                $searchTerm = $this->linkBasedSearchTerm( $employee );
            }
            else
            {
                $searchTerm = $this->nameBasedSearchTerm( $employee );
            }

            $results = $this->API->query( $searchTerm );

            // Bouwt een array van publicatie id's uit de geimporteerde data,
            // nodig om ontbrekende publicaties uit eZ te verwijderen.
            // Voegt eventuele nieuwe attributen toe.
            foreach( $results as $key => $result )
            {
                $idArray[] = $result['pmid'];
                $results[$key]['employees'] = '';
            }

            $data[$employee->ID] = array(
                'object' => $employee,
                'results' => $results
            );

            //print_r($results);
            //foreach( $results as $result )
            //  echo '[[[' . $result['title'] . ']]]' . PHP_EOL;

        }
        $this->data = $data;
        $this->sourceIdArray = array_merge( $this->sourceIdArray, $idArray );
    }

    private function linkBasedSearchTerm( $employee )
    {
        $map = $employee->attribute('data_map');
        $publicationLinks = $map['publications_link']->attribute('content');
        $urlParts = parse_url( $publicationLinks );
        parse_str( $urlParts['query'] );
        return $term;
    }

    private function nameBasedSearchTerm( $employee )
    {
        $map = $employee->attribute('data_map');

        $insertion = trim( $map['insertion']->attribute('content') );
        $lastName = trim( $map['last_name']->attribute('content') );
        if( $insertion ) $lastName = $insertion . "+" . $lastName;
        $initials = str_replace( array( ".", " " ), "", trim( $map['initials']->attribute('content') ) );
        return $lastName . "+" . $initials;
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
            reset($this->current_row);
            $this->current_row = current( $this->data[$this->current_employee['object']->ID]['results'] );
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
                return implode( ", ", $this->current_row['authors'] );

            case 'employees': // object relation list
                $content = $contentObjectAttribute->content();
                $priority = 1;
                //$result = eZSearch::search( $author, array( 'SearchContentClassID' => array( 20 ) ) );
                //print_r($result);
                //$content['relation_list'][] = eZObjectRelationListType::appendObject( $this->current_employee['object']->ID, $priority, $contentObjectAttribute );
                $content = $this->current_employee['object']->ID;
                //$contentObjectAttribute->setContent( $content );
                //$contentObjectAttribute->store();
                return $content;

            default:
                return $this->current_field['value'];
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