<?php
	/*
	 * On app engine, processes a task queue
	 */

	include "init.php";
	include "asana.php";
	use Google\Cloud\PubSub\PubSubClient;

	if (isCancelled($channel)) {
		echo "<p>Cancelled</p>";
		return;
	}

	putenv('GOOGLE_APPLICATION_CREDENTIALS=/Users/mhouston/.config/gcloud/application_default_credentials.json');
	$pubsub = new PubSubClient([
	    'projectId' => $config['project_id']
	]);

	// Get tasks from queue
	echo "<p>Getting topic</p>";
	$topicName = 'tasks-'.$authToken['access_token'];
	$topic = $pubsub->topic($topicName);
	if (!$topic->exists()) {
		echo "<p>Topic $topicName doesn't exist</p>";
		return;
	}
	
	echo "<p>Getting subscription</p>";
	$subscriptionName = 'process-'.$authToken['access_token'];
	$subscription = $topic->subscription($subscriptionName);
	if (!$subscription->exists()) {
		echo "<p>Subscription $subscriptionName does not exist</p>";
		return;
	}

	echo "<p>Getting messages</p>";
	$messages = $subscription->pull(array('returnImmediately' => true));
	// TODO set maxMessages based on remaining rate allowance?
	foreach ($messages as $message) {
		echo '<pre>';
	    print_r($message->data());
	    echo '</pre>';
	    $subscription->acknowledge($message);
	}
?>