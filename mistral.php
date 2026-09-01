<?php

error_reporting(E_ALL);

if (file_exists(dirname(__FILE__) . '/env.php'))
{
	include 'env.php';
}

//----------------------------------------------------------------------------------------
function get($url)
{
	global $config;
	
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
	
	curl_setopt($ch, CURLOPT_HTTPHEADER, 
		array(
			"X-Api-Key: " . getenv('MISTRAL_API_KEY')
			)
		);
	
	$response = curl_exec($ch);
	if($response == FALSE) 
	{
		$errorText = curl_error($ch);
		curl_close($ch);
		die($errorText);
	}
	
	$info = curl_getinfo($ch);
	$http_code = $info['http_code'];
		
	curl_close($ch);
	
	return $response;
}

//----------------------------------------------------------------------------------------
function post($url, $data)
{
	global $config;
	
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
	
	curl_setopt($ch, CURLOPT_POSTFIELDS, $data);  
	
	curl_setopt($ch, CURLOPT_HTTPHEADER, 
		array(
			"Content-Type: application/json",
			"Authorization: Bearer " . getenv('MISTRAL_API_KEY')
			)
		);
	
	$response = curl_exec($ch);
	if($response == FALSE) 
	{
		$errorText = curl_error($ch);
		curl_close($ch);
		die($errorText);
	}
	
	$info = curl_getinfo($ch);
	$http_code = $info['http_code'];
		
	curl_close($ch);
	
	return $response;
}

$filename = '';
if ($argc < 2)
{
	echo "Usage: " . basename(__FILE__) . " <filename>\n";
	exit(1);
}
else
{
	$filename = $argv[1];
}

$file_parts = pathinfo($filename);

$output_filename = $file_parts['filename'] . '-mistral.json';

// type
$mime_type = mime_content_type($filename);

// query
$doc = new stdclass;
$doc->model = 'mistral-ocr-latest';
//$doc->model = 'mistral-ocr-2512';
$doc->document = new stdclass;

// data to ocr
$data = file_get_contents($filename);
$base64 = 'data:' . $mime_type . ';base64,' . base64_encode($data);

switch ($mime_type)
{
	case 'application/pdf':
		$doc->document->type = 'document_url';		
		$doc->document->document_url = $base64;	
		break;
		
	default:
		$doc->document->type = 'image_url';
		$doc->document->image_url = $base64;	
		break;
}

$doc->include_image_base64 = true;
$doc->include_blocks = true;

$url = 'https://api.mistral.ai/v1/ocr';

$result = post($url, json_encode($doc));

file_put_contents($output_filename, $result);

?>
