<?php
	/*
	 * On app engine, processes a task queue
	 */

	include "init.php";
	include "asana.php";
	use Google\Cloud\PubSub\PubSubClient;

	if (isCancelled($channel)) {
		p("Cancelled");
		return;
	}

	$pubsub = new PubSubClient([
	    'projectId' => $config['project_id']
	]);

	// Get tasks from queue
	p("Getting topic");
	$topicName = 'tasks-'.$authToken['access_token'];
	$topic = $pubsub->topic($topicName);
	if (!$topic->exists()) {
		p("Topic $topicName doesn't exist");
		return;
	}
	
	p("Getting subscription");
	$subscription = $topic->subscription('process-tasks');
	if (!$subscription->exists()) {
		p("Subscription does not exist");
		return;
	}

	p("Getting messages");
	$messages = $subscription->pull();
	foreach ($messages as $message) {
	    p($message['data']);
	}
?>