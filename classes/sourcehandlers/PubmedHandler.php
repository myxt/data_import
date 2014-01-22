<?php

class PubmedHandler extends SourceHandler
{
    public $first_employee = true;
    public $first_row = true;
    public $first_field = true;
    public $current_employee;

    public $source_file;
    public $dom;

    public $idPrepend = 'remote_pubmed_';
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
        return self::REMOTE_IDENTIFIER.$this->current_row['articleid'];
    }

    public function getTargetContentClass()
    {
        return $this->classIdentifier;
    }

    public function readData()
    {
        $data = array();

        $employeeClassID = eZContentObjectTreeNode::classIDByIdentifier( 'employee' );
        $employees = eZContentObject::fetchFilteredList( array( 'contentclass_id' => $employeeClassID ), 0, 3 );
        
        foreach( $employees as $employee )
        {

          $map = $employee->attribute('data_map');

          //@TODO Implement query override attribute

          $insertion = trim( $map['insertion']->attribute('content') );
          $lastName = trim( $map['last_name']->attribute('content') );
          if( $insertion ) $lastName = $insertion . "+" . $lastName;
          $initials = str_replace( array( ".", " " ), "", trim( $map['initials']->attribute('content') ) );
          $searchTerm = $lastName . "+" . $initials;
          $results = $this->API->query($searchTerm);

          $data[$employee->ID] = array(
            'object' => $employee,
            'results' => $results
          );

          //print_r($results);
          //foreach( $results as $result )
          //  echo '[[[' . $result['title'] . ']]]' . PHP_EOL;

        }
        $this->data = $data;
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

        //if($this->current_row['articleid'] == 8455427)
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
        return $this->current_field['value'];
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