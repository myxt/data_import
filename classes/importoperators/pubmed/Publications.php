<?php

class Publications extends ImportOperator
{

  function __construct( $handler )
  {
    $this->source_handler = $handler;
    $this->source_handler->logger = new eZLog();
    $this->cli = eZCLI::instance();
    $this->cli->setUseStyles( true );
  }

  public function run()
  {
    $this->updatePublications();
    $this->checkExistingPublications();
  
  }

  public function updatePublications()
  {
    $offset = 0;
    $limit = 10;
    $max = 10000;
    $total = 0;
    $employeeClassID = eZContentObjectTreeNode::classIDByIdentifier( 'employee' );

    $this->cli->output( "Fetching data for year " . $this->source_handler->year);

    while( $employees = eZPersistentObject::fetchObjectList( eZContentObject::definition(), null, array( 'contentclass_id' => $employeeClassID ), array( 'name' => 'asc' ), array( 'offset' => $offset, 'length' => $limit ) ) )
    //while( $employees = eZContentObject::fetchFilteredList( array( 'contentclass_id' => $employeeClassID ), $offset, $limit ) )
    {
      if( !count($employees) || $total >= $max ) break;

      $this->cli->output( "\nFetched with offset " . $offset . ". Total is " . $total . ".\n", false );

      $this->source_handler->readData( $employees );

      $this->source_handler->first_employee = true; // Reset employee counter
      while( $this->source_handler->getNextEmployee() )
      {
        $this->cli->output( "\nUpdating publications for employee " . $this->cli->stylize( 'emphasize', $this->source_handler->current_employee['object']->Name . " (" . $this->source_handler->current_employee['object']->ID . ")" ) . $this->source_handler->current_employee['queryType'] . ":\n", false );
        $this->importRemotePublications();
        $total += 1;
      }

      $offset += $limit;
    }

    $this->cli->output( "\nUpdated " . $total . " matching employees with a total of " . count( $this->source_handler->sourceIdCache ) . " PubMed records.\n", false );

  }

  public function checkExistingPublications()
  {
    //print_r( $this->source_handler->sourceIdCache );
    $this->cli->output( "\nChecking existing publications eZ Publish.\n", false );
    
    $offset = 0;
    $limit = 100;
    $max = 10000;
    $total = 1;
    $publicationClassID = eZContentObjectTreeNode::classIDByIdentifier( $this->source_handler->classIdentifier );
    while( $publications = eZContentObject::fetchFilteredList( array( 'contentclass_id' => $publicationClassID ), $offset, $limit ) )
    {
      if( !count($publications) || $total > $max ) break;

      foreach( $publications as $object )
      {
        $remoteID = $object->attribute('remote_id');
        
        $this->cli->output( "  Checking object (".$this->cli->stylize( 'emphasize', $remoteID ).") of type (".$this->cli->stylize( 'emphasize', $this->source_handler->getTargetContentClass() ).")... " , false );
        $sourceID = $this->extractIdFromRemoteId( $remoteID );

        if( isset( $object->attribute('data_map')['manual_override'] ) && $object->attribute('data_map')['manual_override']->attribute('content') )
        {
          $this->cli->output( $this->cli->stylize( 'gray', "skipped (manual).\n" ), false );
        }

        elseif( isset( $object->attribute('data_map')['year'] ) && $object->attribute('data_map')['year']->attribute('content') !== $this->source_handler->year )
        {
          $this->cli->output( $this->cli->stylize( 'gray', "skipped (year).\n" ), false );
        }

        elseif( in_array( $sourceID , $this->source_handler->sourceIdCache ) )
        {
          $this->cli->output( $this->cli->stylize( 'gray', "skipped (current).\n" ), false );
        }

        else
        {
          try {
            $this->remove_eZ_object( $object );
          }
          catch( Exception $e ) {
            $this->cli->output( "Error deleting object.", false );
          }
          $this->cli->output( $this->cli->stylize( 'green', "successfully removed.\n" ), false );
          $clearCache = true;
        }
        $total += 1;
      }
      $offset += $limit;
    }

  }

  public function importRemotePublications()
  {
      $force_exit = false;
      while( $this->source_handler->getNextRow() && !$force_exit )
      {
        $this->current_eZ_object = null;
        $this->current_eZ_version = null;
        
        $remoteID        = $this->source_handler->getDataRowId();
        $targetLanguage  = $this->source_handler->getTargetLanguage();

        $this->cli->output( '  Importing remote object ('.$this->cli->stylize( 'emphasize', $remoteID ).') ', false );

        $this->current_eZ_object = eZContentObject::fetchByRemoteID( $remoteID );

        if( $this->current_eZ_object && $this->current_eZ_object->attribute('data_map')['manual_override']->attribute('content') )
        {
          $this->cli->output( "skipping ". $this->current_eZ_object->attribute( 'id' ) . " (manual override)" . ".\n", false );
        }

        if( $this->current_eZ_object && $this->current_eZ_object->attribute('data_map')['year']->attribute('content') !== $this->source_handler->year )
        {
          $this->cli->output( "skipping ". $this->current_eZ_object->attribute( 'id' ) . " (year)" . ".\n", false );
        }

        if( !$this->current_eZ_object )
        {
          $this->storeMode = 'create';
          $this->create_eZ_node( $remoteID, $targetContentClass, $targetLanguage );
        }
        else
        {
          $this->storeMode = 'update';
          $this->update_eZ_node( $remoteID, $targetLanguage );
        }

        if( $this->current_eZ_object && $this->current_eZ_version )
        {
          if( $this->save_eZ_node() )
          {
            if( $this->publish_eZ_node() )
            {
              $this->setNodesPriority();

              $this->cli->output( $this->cli->stylize( 'green', 'object ID ( '. $this->current_eZ_object->attribute( 'id' ) . ' )' . ".\n" ), false );
            }
            else
            {
              $this->cli->output( $this->cli->stylize( 'red', 'failed. Post handling after publish not successful.'."\n" ), false );
            }
          }
          else
          {
            $this->cli->output( $this->cli->stylize( 'red', 'failed. Post handling after save not successful.'."\n" ), false );
          }
          
          # Clear content object from $GLOBALS - to prevent OOM (not mana)
          unset( $GLOBALS[ 'eZContentObjectContentObjectCache' ] );
          unset( $GLOBALS[ 'eZContentObjectDataMapCache' ] );
          unset( $GLOBALS[ 'eZContentObjectVersionCache' ] ); 
        }
        else
        {
          $this->cli->output( '..'.$this->cli->stylize( 'gray', 'skipped.'."\n" ), false );
        }

      }
  }

  public function publicationExists( $id )
  {
    /*
    $oarams = array( "AsObject" => true,
                     "ClassFilterType" => "include",     
                     "ClassFilterArray" => array( "publication" ),
                     "AttributeFilter"  => array( "and",
                                                  array( "publication/articleid", "=", $articleId )
                                           )
    );

    $publications = eZContentObjectTreeNode::subTreeByNodeID( $params, 2 );
    */
    return eZContentObject::fetchByRemoteID( $id );
  }

  function extractIdFromRemoteId( $remoteID )
  {
    $remoteParts = explode( $this->source_handler->idPrepend, $remoteID );
    return $remoteParts[1];
  }

  protected function save_eZ_attribute( eZContentObjectAttribute $contentObjectAttribute )
  {
    $value = '';

    switch( $contentObjectAttribute->attribute( 'data_type_string' ) )
    {
      case 'ezobjectrelation':
      {
        // Remove any exisiting value first from ezobjectrelation
        /*
        eZContentObject::removeContentObjectRelation( $contentObjectAttribute->attribute('data_int'),
                                                      $this->current_eZ_object->attribute('current_version'),
                                                      $this->current_eZ_object->attribute('id'),
                                                      $contentObjectAttribute->attribute('contentclassattribute_id')
                                                      );
        */
        $contentObjectAttribute->setAttribute( 'data_int', 0 );
        $contentObjectAttribute->store();

        $value = $this->source_handler->getValueFromField( $contentObjectAttribute );
      }
      break;
      
      case 'ezobjectrelationlist':
      {
        // Remove any exisiting value first from ezobjectrelationlist
        /*
        $content = $contentObjectAttribute->content();
        $relationList =& $content['relation_list'];
        $newRelationList = array();
        for ( $i = 0; $i < count( $relationList ); ++$i )
        {
            $relationItem = $relationList[$i];
            eZObjectRelationListType::removeRelationObject( $contentObjectAttribute, $relationItem );
        }
        $content['relation_list'] = $newRelationList;
        $contentObjectAttribute->setContent( $content );
        $contentObjectAttribute->store();
        */
        
        $value = $this->source_handler->getValueFromField( $contentObjectAttribute );

      }
      break;

      default:
        $value = $this->source_handler->getValueFromField( $contentObjectAttribute );
    }

    $contentObjectAttribute->fromString( $value );
    $contentObjectAttribute->store();
  }

  /**
   * Override to prevent the creation of a new object version.
   * @param  [type] $force_exit [description]
   * @return [type]             [description]
   */
  /*
  protected function publish_eZ_node( $force_exit )
  {
    if( $this->storeMode == 'create' )
    {
      eZOperationHandler::execute(
          'content',
          'publish',
          array(
              'object_id' => $this->current_eZ_object->attribute( 'id' ),
              'version'   => $this->current_eZ_version->attribute( 'version' ),
          )
      );
    }
      
    return $this->source_handler->post_publish_handling( $this->current_eZ_object, $force_exit );
  }
  */

}
