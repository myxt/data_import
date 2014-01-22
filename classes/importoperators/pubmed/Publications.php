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
    $this->source_handler->readData();

    while( $this->source_handler->getNextEmployee() )
    {
      $this->cli->output( "\nImporting publications for employee " . $this->cli->stylize( 'emphasize', $this->source_handler->current_employee['object']->Name . " (" . $this->source_handler->current_employee['object']->ID . ")" ) . ":\n", false );

      $force_exit = false;
      while( $this->source_handler->getNextRow() && !$force_exit )
      {
        $this->current_eZ_object = null;
        $this->current_eZ_version = null;
        
        $remoteID        = $this->source_handler->getDataRowId();
        $targetLanguage  = $this->source_handler->getTargetLanguage();

        $this->cli->output( 'Importing remote object ('.$this->cli->stylize( 'emphasize', $remoteID ).') ', false );

        $this->current_eZ_object = eZContentObject::fetchByRemoteID( $remoteID );

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
  
  }

  public function publicationExists( $articleId )
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
    return eZContentObject::fetchByRemoteID( $articleId );
  }

}
