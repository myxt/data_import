<?php

#################
#  Setting up env
#################

require 'autoload.php';

if ( file_exists( "config.php" ) )
{
    require "config.php";
}

$params = new ezcConsoleInput();

$helpOption = new ezcConsoleOption( 'h', 'help' );
$helpOption->mandatory = false;
$helpOption->shorthelp = "Show help information";
$params->registerOption( $helpOption );

$source_handler_option = new ezcConsoleOption( 'd', 'source_handler', ezcConsoleInput::TYPE_STRING );
$source_handler_option->mandatory = true;
$source_handler_option->shorthelp = "The source handler class name.";
$params->registerOption( $source_handler_option );

$import_operator_option = new ezcConsoleOption( 'i', 'import_handler', ezcConsoleInput::TYPE_STRING );
$import_operator_option->mandatory = false;
$import_operator_option->shorthelp = 'The import handler class name.';
$import_operator_option->default = 'ImportOperator';
$params->registerOption( $import_operator_option );

$options_option = new ezcConsoleOption( 'o', 'options', ezcConsoleInput::TYPE_STRING );
$options_option->mandatory = false;
$options_option->shorthelp = 'Options string (For example -o foo=bar,foo2=bar2 ).';
$params->registerOption( $options_option );

$siteaccess_option = new ezcConsoleOption( 's', 'siteaccess', ezcConsoleInput::TYPE_STRING );
$siteaccess_option->mandatory = false;
$siteaccess_option->shorthelp = "The siteaccess name.";
$params->registerOption( $siteaccess_option );

// Process console parameters
try
{
    $params->process();
}
catch ( ezcConsoleOptionException $e )
{
	echo $e->getMessage(). "\n";
	echo "\n";
	echo $params->getHelpText( 'data_import run script.' ) . "\n";
    echo "\n";
    exit();
}

// Init an eZ Publish script - needed for some API function calls
// and a siteaccess switcher

$ezp_script_env = eZScript::instance( array( 'debug-message' => '',
                                              'use-session' => true,
                                              'use-modules' => true,
                                              'use-extensions' => true ) );

$ezp_script_env->startup();

if( $siteaccess_option->value )
{
	$ezp_script_env->setUseSiteAccess( $siteaccess_option->value );
}
$ezp_script_env->initialize();


####################
# Script process
####################

// Elevate user rights.
$user = eZUser::fetchByName('admin');
if( !$user )
	MyxteTreatmentLog::writeError( 'Error elevating cronjob user rights', 'process_treatments.php' );
else
	$user->loginCurrent();

$source_handler_id  = $source_handler_option->value;
$import_operator_id = $import_operator_option->value;
$options_str        = $options_option->value;

// Build parameter array from given string
$parameters = null;
if( $options_str )
{
	$options_parts = explode( ',', $options_str );

	if( !empty( $options_parts ) )
	{
		foreach( $options_parts as $part )
		{
			$i_operation = explode( '=', $part );
				
			if( !empty( $i_operation) && count( $i_operation ) == 2 )
			{
				$parameters[ $i_operation[0] ] = $i_operation[1];
			}
		}
	}
}


if( class_exists( $source_handler_id ) )
{
	$source_handler = new $source_handler_id;
	$source_handler->init( $parameters );
	
	if( class_exists( $import_operator_id ) )
	{
		$import_operator = new $import_operator_id( $source_handler );
		$import_operator->run();
	}
	else
	{
		echo 'Could not get an instance of the import operator ( '. $import_operator_id .' ).' . "\n";
	}
}
else
{
	echo 'Could not get an instance of the source handler ( '. $source_handler_id .' ).' . "\n";
}

// Avoid fatal error at the end
$ezp_script_env->shutdown();

####
# Functions
####

?>