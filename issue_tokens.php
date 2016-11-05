<?php

	// Issues tokens for any pending jobs and adds them to the queue

	use Google\Cloud\Datastore\DatastoreClient;

	// TODO check memcache to see if there are no pending jobs and avoid a DS query

	$datastore = new DatastoreClient();

	$query = $datastore->query()
		->kind('Job');

	$result = $datastore->runQuery($query);

	foreach ($result as $entity) {
	    echo '<pre>';
	    print_r($entity);
	    echo '</pre>';
	}
