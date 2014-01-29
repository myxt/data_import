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
    $offset = 0;
    $limit = 5;
    $max = 5;
    $total = 1;
    $employeeClassID = eZContentObjectTreeNode::classIDByIdentifier( 'employee' );

    while( $employees = eZContentObject::fetchFilteredList( array( 'contentclass_id' => $employeeClassID ), $offset, $limit ) )
    {
      if( !count($employees) || $total > $max ) break;

      $this->source_handler->readData( $employees );

      while( $this->source_handler->getNextEmployee() )
      {
        $this->cli->output( "\nUpdating publications for employee " . $this->cli->stylize( 'emphasize', $this->source_handler->current_employee['object']->Name . " (" . $this->source_handler->current_employee['object']->ID . ")" ) . ":\n", false );
        $this->importRemotePublications();
        $total += 1;
      }

      $this->cli->output( "\n" . $total . "-" . $total + $limit . "\n", false );
      $offset += $limit;
    }

    $this->cli->output( "\nProcessed " . $total . " employees with a total of " . count( $this->source_handler->sourceIdArray ) . " PubMed records.\n", false );

    $this->checkExistingPublications();
  
  }

  public function checkExistingPublications()
  {
    //print_r( $this->source_handler->sourceIdArray );
    $this->cli->output( "\nChecking existing publications eZ Publish.\n", false );
    
    $classIdentifier = $this->source_handler->classIdentifier;
    $parentNode = eZContentObjectTreeNode::fetch( $this->source_handler->parentNodeID );
    $nodes = $parentNode->attribute( 'children' );

    foreach( $nodes as $node )
    {
      if( $node->ClassIdentifier == $classIdentifier )
      {
        $object = eZContentObject::fetchByNodeID( $node->attribute('node_id') );
        $remoteID = $object->attribute('remote_id');
        
        $this->cli->output( "  Checking object (".$this->cli->stylize( 'emphasize', $remoteID ).") of type (".$this->cli->stylize( 'emphasize', $this->source_handler->getTargetContentClass() ).")... " , false );
        $sourceID = $this->extractIdFromRemoteId( $remoteID );

        if( $object->attribute('data_map')[manual_override]->attribute('content') )
        {
          $this->cli->output( $this->cli->stylize( 'gray', "skipped (manual).\n" ), false );
        }

        elseif( !in_array( $sourceID , $this->source_handler->sourceIdArray ) )
        {
          $this->remove_eZ_object( $object );
          $this->cli->output( $this->cli->stylize( 'green', "successfully removed.\n" ), false );
          $clearCache = true;
        }
        else
        {

          $this->cli->output( $this->cli->stylize( 'gray', "skipped.\n" ), false );
        }
      }
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

        if( $this->current_eZ_object && $this->current_eZ_object->attribute('data_map')[manual_override]->attribute('content') )
        {
          $this->cli->output( "skipping ". $this->current_eZ_object->attribute( 'id' ) . " (manual override)" . ".\n", false );
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

}
