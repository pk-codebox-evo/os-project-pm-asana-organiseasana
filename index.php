<?php
date_default_timezone_set("UTC");
header('Content-Type: text/html; charset=utf-8');

include "asana.php";

// Input parameters

global $DEBUG;
$DEBUG = false;
if (isset($_COOKIE["debug"]))
	$DEBUG = $_COOKIE["debug"];
if (isset($_GET["debug"]))
	$DEBUG = $_GET["debug"];

if ($DEBUG) {
	setcookie("debug", $DEBUG);
}

global $apiKey;
$apiKey = "";
$storeKey = null;
$targetWorkspaceId = null;
$copy = null;
$workspaceId = null;
$projects = null;
$teamId = null;

// Read parameters
if (isset($_COOKIE["apiKey"]))
	$apiKey = $_COOKIE["apiKey"];
if (isset($_POST["apiKey"]))
	$apiKey = $_POST["apiKey"];
if (isset($_POST["storeKey"]))
	$storeKey = $_POST["storeKey"];

if (isset($_POST["new_workspace"])) {
	$workspaceId = $_POST["new_workspace"];
}
else {
	if (isset($_POST["workspace"]))
		$workspaceId = $_POST["workspace"];

	if (isset($_POST["projects"]))
		$projects = $_POST["projects"];

	if (isset($_POST["new_targetWorkspace"])) {
		$targetWorkspaceId = $_POST["new_targetWorkspace"];
	}
	else {
		if (isset($_POST["targetWorkspace"]))
			$targetWorkspaceId = $_POST["targetWorkspace"];
		if (isset($_POST["copy"]))
			$copy = $_POST["copy"];
		if (isset($_POST["team"]))
			$teamId = $_POST["team"];
	}
}

// Store for 90 days
if ($apiKey && $storeKey) {
	setcookie("apiKey", $apiKey, time()+60*60*24*90);
}

function isOrganisation($workspace) {
	$org = isset($workspace['is_organization']) && $workspace['is_organization'];
	if ($workspace['name'] == 'Personal Projects')
		$org = false;
		
	return $org;
}

?>
<html>
	<head>
		<title>Organise Asana Projects</title>
		<script src="//code.jquery.com/jquery-1.11.0.min.js"></script>

		<link rel="stylesheet" href="//netdna.bootstrapcdn.com/bootstrap/3.1.1/css/bootstrap.min.css">
		<!-- <link rel="stylesheet" href="//netdna.bootstrapcdn.com/bootstrap/3.1.1/css/bootstrap-theme.min.css"> -->
		<script src="//netdna.bootstrapcdn.com/bootstrap/3.1.1/js/bootstrap.min.js"></script>

		<style>
			form input[type=text] { width: 500px; }

			.bs-callout h4 {
				margin-top: 0;
				margin-bottom: 5px;
			}
			.bs-callout-info h4 {
				color: #5bc0de;
			}
			.bs-callout-warning h4 {
				color: #f0ad4e;
			}
			.bs-callout-danger h4 {
				color: #d9534f;
			}

			.bs-callout {
				margin: 20px 0;
				padding: 20px;
				border-left: 3px solid #eee;
			}
			.bs-callout-info {
				background-color: #f4f8fa;
				border-color: #5bc0de;
			}
			.bs-callout-warning {
				background-color: #fcf8f2;
				border-color: #f0ad4e;
			}
			.bs-callout-danger {
				background-color: #fdf7f7;
				border-color: #d9534f;
			}
		</style>
	</head>
	<body>
		<div class="container">
			<div class="page-header">
				<h1>Organise Asana Projects <small><a href="http://kothar.net/projects/organise-asana.html">Help!</a></small></h1>
			</div>
			<p class="lead">
				Copy <a href="https://asana.com" target="asana">Asana</a> projects from one workspace to another.
			</p>
			<form role="form" method="POST">
				<div class="row">
					<div class="col-sm-8">
						<h2>Enter your API key to access Asana</h2>
						<label>API Key: <input type="text" name="apiKey" value="<?php echo $apiKey ?>"></label>
						<button class="btn btn-primary btn-sm" type="submit">Submit</button><br>
						<label><input type="checkbox" name="storeKey" value="1"> Remember key (stores cookie)</label>
					</div>
				</div>

				<?php if($DEBUG) { ?>
				<div class="bs-callout bs-callout-info">
					<h4>DEBUG Mode is ON</h4>
					<a href="?debug=0" class="btn btn-warning">Disable</a>

					<div class="btn-group">
						<a href="?debug=1" class="btn btn-default">Level 1</a>
						<a href="?debug=2" class="btn btn-default">Level 2 (show API calls)</a>
					</div>
				</div>
				<?php } ?>

				<?php if ($apiKey) {

					$targetWorkspace = null;
					$team = null;

					if ($targetWorkspaceId) {
						$targetWorkspace = getWorkspace($targetWorkspaceId);
					}

					if ($DEBUG) pre($targetWorkspace, "Target Workspace");

					// Do we have what we need to run a copy?
					if ($targetWorkspaceId && $projects) {
						if (isOrganisation($targetWorkspace)) {
							if ($teamId) {
								$team = getTeam($targetWorkspaceId, $teamId);
								if ($DEBUG) pre($team, "Team");
								if ($team)
									$copy = true;
							}
						}
					}

					// Run the copy operation
					if ($copy) {

						$teamName = '';
						if ($team)
							$teamName = '/' . $team['name'];
						echo '<h2>Copying Projects to '. $targetWorkspace['name'] . $teamName . '</h2>';

						$newProjects = array();

						for ($i = count($projects) - 1; $i >= 0; $i--) {
							$project = getProject($projects[$i]);
							$targetProjectName = $project['name'];
							$notes = $project['notes'];

							// Check for an existing project in the target workspace
							$targetProjects = getProjects($targetWorkspaceId);
							if ($DEBUG) pre($targetProjects);

							$count = 2;
							$found = false;
							do {
								$found = false;
								for ($j = 0; $j < count($targetProjects); $j++) {
									if (strcmp($targetProjects[$j]['name'], $targetProjectName) == 0) {
										$targetProjectName = $project['name'] . ' ' . $count++;
										$found = true;
										break;
									}
								}
							}
							while ($found == true && $count < 100);

							// Create target project
							echo '<h4>Copying ' . $project['name'] . ' to ' . $targetWorkspace['name'] . $teamName . '/' . $targetProjectName . '</h4>';
							flush();
							$targetProject = createProject($targetWorkspaceId, $targetProjectName, $teamId, $notes);
							$newProjects[] = $targetProject;

							// Run copy
							copyTasks($project['id'], $targetProject['id']);
						}

						echo '<b>Done</b>';

						echo '<h4>View in Asana</h4>';
						echo '<div class="btn-group">';
						for ($i = count($newProjects) - 1; $i >= 0; $i--) {
							$project = $newProjects[$i];
							echo '<a class="btn btn-success btn-xs" target="asana" href="https://app.asana.com/0/' . $project['id'] . '">' . $project['name'] . '</a>';
						}
						echo '</div>';
					}

					// Display copy options
					else {
						echo '<h2>Browse workspace</h2>';
						echo '<div class="btn-group">';
						$workspaces = getWorkspaces();
						for ($i = count($workspaces) - 1; $i >= 0; $i--)
						{
							$workspace = $workspaces[$i];
							$active = '';
							if ($workspace['id'] == $workspaceId)
								$active = ' active';
							echo '<button class="btn btn-default' . $active . '" type="submit" name="new_workspace" value="' . $workspace['id'] . '">' . $workspace['name'] . '</button>';
						}
						echo '</div>';

						if ($workspaceId) {
							echo '<input type="hidden" name="workspace" value="' . $workspaceId . '"></input>';

							// Select projects
							echo '<div class="row">';
							echo '<div class="col-sm-4">';
							echo '<h2>Copy Projects -></h2>';

							$workspaceProjects = getProjects($workspaceId);
							$names = function($value) { return $value['name']; };
							array_multisort(array_map($names, $workspaceProjects), SORT_DESC, $workspaceProjects);

							echo '<div class="btn-group-vertical" data-toggle="buttons">';
							for ($i = count($workspaceProjects) - 1; $i >= 0; $i--)
							{
								$project = $workspaceProjects[$i];
								$checked = '';
								$active = '';
								if ($projects && in_array($project['id'], $projects)) {
									$checked = ' checked';
									$active = ' active';
								}

								echo '<label class="btn btn-default' . $active . '"><input type="checkbox" name="projects[]" value="'
										. $project['id'] . '"' . $checked . '> ' . $project['name'] . '</label>';
							}
							echo '</div>';

							if ($DEBUG) pre($projects, "Selected projects");
							if ($DEBUG) pre($workspaceProjects, "Workspace projects");
							echo '</div>';

							// Select workspace
							echo '<div class="col-sm-4">';
							echo '<h2>to Workspace</h2>';

							echo '<div class="btn-group-vertical">';
							for ($i = count($workspaces) - 1; $i >= 0; $i--)
							{
								$workspace = $workspaces[$i];

								$type = ' btn-default';
								if (isOrganisation($workspace))
									$type = ' btn-warning';

								$active = '';
								if ($targetWorkspaceId == $workspace['id'])
									$active = ' active';
								echo '<button class="btn' . $type . $active . '" type="submit" name="new_targetWorkspace" value="' . $workspace['id'] . '">' . $workspace['name'] . '</button>';
							}
							echo '</div>';

							if ($DEBUG) pre($workspaces, "Target workspaces");
							echo '</div>';

							// Select team
							if ($targetWorkspaceId) {
								echo '<div class="col-sm-4">';
								echo '<input type="hidden" name="targetWorkspace" value="' . $targetWorkspaceId . '"></input>';
								$showTeams = isOrganisation($targetWorkspace);

								// Handle Personal Projects
								if ($showTeams) {
									$teams = getTeams($targetWorkspaceId);
									
									echo '<h2>for team</h2>';

									echo '<div class="btn-group-vertical">';
									for ($i = count($teams) - 1; $i >= 0; $i--)
									{
										$team = $teams[$i];

										echo '<button class="btn btn-success" type="submit" name="team" value="' . $team['id'] . '">' . $team['name'] . '</button>';
									}
									echo '</div>';

									if ($DEBUG) pre($teams, "Available teams");
								}
								else {
									// GO button

									echo '<h2>Ready!</h2>';
									echo '<button class="btn btn-success" type="submit" name="copy" value="go">Go!</button>';

								}
								echo '</div>';
							}

							echo '</div>';
						}
					}

				} ?>
			</form>

			<a class="btn btn-primary btn-xs" href=".">Back to start</a>

			<div class="bs-callout bs-callout-info">
				<h4>Source code</h4>
				<p>Source code for this tool can be found at <a href="https://bitbucket.org/mikehouston/organiseasana">https://bitbucket.org/mikehouston/organiseasana</a></p>
				<p>The implementation of the copy operation is based on <a href="https://gist.github.com/AWeg/5814427">https://gist.github.com/AWeg/5814427</a></p>
				<h4>Privacy</h4>
				<p>No data is stored on the server - the API key is not retained between calls. No cookies are stored, unless you request the API key to be remembered.</p>
				<h4>No Warranty</h4>
				<p>This tool does not delete any data, and will not modifiy any existing projects (a new copy is made each time)</p>
				<p>No warranty is made however - use at your own risk</p>
			</div>
		</div>
	</body>
</html>