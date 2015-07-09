<?php

set_time_limit(0);

require 'vendor/autoload.php';

// Doc URL : http://docs.guzzlephp.org/en/latest/#
use GuzzleHttp\Client;

class Sonar {

	protected $api_host = 'localhost';
	protected $api_port = '9000';
	
	protected $api_url = 'http://localhost:9000/api/';
	protected $api_method = 'issues/search';
	
	// Page size. Must be greater than 0.
	// Default value: 100; Maximum Value: 500
	protected $page_size = 500;

	// To retrieve issues associated to a specific list of projects 
	// (comma-separated list of project keys).
	protected $project_key;

	// Comma-separated list of severities
	// Possible values:
	// INFO MINOR MAJOR CRITICAL BLOCKER
	// Example value: BLOCKER,CRITICAL
	protected $severities;

	protected $columns = array('summary' => 1);

	private $response;
	
	
	public function __construct($sonarqube_host, $sonarqube_port, $project_key, $severities, $columns) {
		if ($sonarqube_host != '') {
			$this->api_host = $sonarqube_host;
		}
		if ($sonarqube_port != '') {
			$this->api_port = $sonarqube_port;
		}
		$this->api_url = "http://{$this->api_host}:{$this->api_port}/api/";
		$this->project_key = $project_key;
		$this->severities = implode(',', $severities);
		$this->columns = array_merge(array_flip($columns), $this->columns);
	}
	

	public function getIssues($page) {

		$client = new Client();

		$request = $client->createRequest('GET', $this->api_url . $this->api_method);
		$query = $request->getQuery();
		$query->set('ps', $this->page_size);
		$query->set('p', $page);

		if ($this->project_key) {
			$query->set('projectKeys', $this->project_key);
		}
		if ($this->severities) {
			$query->set('severities', $this->severities);
		}

		$this->response = $client->send($request)->json();
		
		return $this;
		
	}

	public function createReport($mode = 'w', $first = true) {
	
		$fp = fopen("{$this->project_key}.csv", $mode);
		if ($first) {
			fputcsv($fp, array_keys($this->columns));
		}	
		foreach ($this->response['issues'] as $issues) {
			$issues['component'] = str_replace("{$this->project_key}:", "", $issues['component']);
			$line = (isset($issues['line'])) ? 'Line: ' . $issues['line'] : '';
			$issues['summary'] = 'Severity: ' . $issues['severity'] . "\n" . $issues['message'] . "\n" . $line;
			fputcsv($fp, array_intersect_key($issues, $this->columns));
		}
		fclose($fp);
		
		return $this;
	}
	
	public function downloadReport($mode = 'w', $first = true) {
	
		$fp = fopen("php://output", $mode);
		if ($first) {
			ob_end_clean();
			header( "Content-Type: text/csv" );
			header( "Content-Disposition: attachment;filename={$this->project_key}.csv");
			fputcsv($fp, array_keys($this->columns));
		}
		foreach ($this->response['issues'] as $issues) {
			$issues['component'] = str_replace("{$this->project_key}:", "", $issues['component']);
			$line = (isset($issues['line'])) ? 'Line: ' . $issues['line'] : '';
			$issues['summary'] = 'Severity: ' . $issues['severity'] . "\n" . $issues['message'] . "\n" . $line;
			fputcsv($fp, array_intersect_key($issues, $this->columns));
		}
		fclose($fp);
		
		return $this;
	}
	
	public function process() {
		$total = $this->response['total'];
		$pages = ceil($total / $this->page_size);

		for ($i = 2; $i <= $pages; $i++) {
			$this->getIssues($i)->downloadReport('a', false);
		}
		
		exit();
		
		return "Issues Report created for <strong>$this->project_key<strong>";
	}

}

if (isset($_POST['submit'])) {
	$sonarqube_host = $_POST['sonarqube_host'];
	$sonarqube_port = $_POST['sonarqube_port'];
	$project_key = $_POST['project_key'];
	$severities = (isset($_POST['severities'])) ? $_POST['severities'] : array();
	$columns = (isset($_POST['columns'])) ? $_POST['columns'] : array();
	
	$error = array();	
	if ($project_key == '') {
		$error[] = 'Project key is required!';
	}
	if (empty($severities)) {
		$error[] = 'Select atleast one Severity!';
	}
	if (empty($columns)) {
		$error[] = 'Select atleast one Column!';
	}
	
	if (empty($error)) {
		try {
			$obj_sonar = new Sonar($sonarqube_host, $sonarqube_port, $project_key, $severities, $columns);
			$success = $obj_sonar->getIssues(1)->downloadReport()->process();
			$project_key = $sonarqube_host = $sonarqube_port = '';
			$severities = array('INFO', 'MINOR', 'MAJOR', 'CRITICAL', 'BLOCKER');
			$columns = array('component', 'severity', 'message');
		} catch(Exception $e) {
			$error[] = $e->getMessage();
		}
	}	
} else {
	$project_key = $sonarqube_host = $sonarqube_port = '';
	$severities = array('INFO', 'MINOR', 'MAJOR', 'CRITICAL', 'BLOCKER');
	$columns = array('component', 'severity', 'message');
}

?>

<!-- Latest compiled and minified CSS -->
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.4/css/bootstrap.min.css">

<!-- Latest compiled and minified JavaScript -->
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.4/js/bootstrap.min.js"></script>

<style>

body {
  width: 60%;
  margin: 20px auto;
}

</style>

<div class="panel panel-primary">
  <div class="panel-heading">Generate Issues Report</div>
  <div class="panel-body">
  
	<?php
		if (isset($error) && !empty($error)) :
			echo '<div class="alert alert-danger" role="alert"><ul><li>' . implode ('</li><li>', $error) . '</li></ul></div>';
		endif;
		
		if (isset($success)) :
			echo '<div class="alert alert-success" role="alert">' . $success . '</div>';
		endif;
	?>
  
	<form method=post class="form-horizontal">

		<div class="form-group">
			<label for="sonarqube_host" class="col-sm-3 control-label">SonarQube Host</label>
			<div class="col-sm-5">
				<input type=text name=sonarqube_host value="<?php echo $sonarqube_host ?>" class="form-control" id="sonarqube_host" placeholder="localhost">
			</div>
		</div>
		
		<div class="form-group">
			<label for="sonarqube_port" class="col-sm-3 control-label">SonarQube Port</label>
			<div class="col-sm-5">
				<input type=text name=sonarqube_port value="<?php echo $sonarqube_port ?>" class="form-control" id="sonarqube_port" placeholder="9000">
			</div>
		</div>
		
		<div class="form-group">
			<label for="project_key" class="col-sm-3 control-label">Project Key</label>
			<div class="col-sm-5">
				<input type=text name=project_key value="<?php echo $project_key ?>" class="form-control" id="project_key" placeholder="Project Key">
			</div>
		</div>
		
		<div class="form-group">
			<label class="col-sm-3 control-label">Severities</label>
			<div class="col-sm-9">
				<label class="checkbox-inline">
				  <input type="checkbox" name="severities[]" value="INFO" <?php echo (in_array('INFO', $severities)) ? 'checked' : '' ?>> INFO
				</label>
				<label class="checkbox-inline">
				  <input type="checkbox" name="severities[]" value="MINOR" <?php echo (in_array('MINOR', $severities)) ? 'checked' : '' ?>> MINOR
				</label>
				<label class="checkbox-inline">
				  <input type="checkbox" name="severities[]" value="MAJOR" <?php echo (in_array('MAJOR', $severities)) ? 'checked' : '' ?>> MAJOR
				</label>
				<label class="checkbox-inline">
				  <input type="checkbox" name="severities[]" value="CRITICAL" <?php echo (in_array('CRITICAL', $severities)) ? 'checked' : '' ?>> CRITICAL
				</label>
				<label class="checkbox-inline">
				  <input type="checkbox" name="severities[]" value="BLOCKER" <?php echo (in_array('BLOCKER', $severities)) ? 'checked' : '' ?>> BLOCKER
				</label>
			</div>
		</div>
		
		<div class="form-group">
			<label class="col-sm-3 control-label">Columns</label>
			<div class="col-sm-9">
			  	<label class="checkbox-inline">
				  <input type="checkbox" name="columns[]" value="project" <?php echo (in_array('project', $columns)) ? 'checked' : '' ?>> PROJECT
				</label>
				<label class="checkbox-inline">
				  <input type="checkbox" name="columns[]" value="component" <?php echo (in_array('component', $columns)) ? 'checked' : '' ?>> COMPONENT
				</label>
				<label class="checkbox-inline">
				  <input type="checkbox" name="columns[]" value="severity" <?php echo (in_array('severity', $columns)) ? 'checked' : '' ?>> SEVERITY
				</label>
				<label class="checkbox-inline">
				  <input type="checkbox" name="columns[]" value="line" <?php echo (in_array('line', $columns)) ? 'checked' : '' ?>> LINE
				</label>
				<label class="checkbox-inline">
				  <input type="checkbox" name="columns[]" value="message" <?php echo (in_array('message', $columns)) ? 'checked' : '' ?>> MESSAGE
				</label>
			 </div>
		</div>
		
		<div class="form-group">
			<div class="col-sm-offset-3 col-sm-9">
			  <button type="submit" name="submit" class="btn btn-primary">Download</button>
			</div>
		</div>
		
	</form>

  </div>
</div>