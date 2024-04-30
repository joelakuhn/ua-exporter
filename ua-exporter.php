#!/usr/bin/env php
<?php

use Google\Http\REST;
use Google\Service\AnalyticsReporting;
use Google\Service\AnalyticsReporting\DateRange;
use Google\Service\AnalyticsReporting\Metric;
use Google\Service\AnalyticsReporting\Dimension;
use Google\Service\AnalyticsReporting\ReportRequest;
use Google\Service\AnalyticsReporting\GetReportsRequest;
use Google\Service\AnalyticsReporting\GetReportsResponse;

// Load the Google API PHP Client Library.
require_once __DIR__ . '/vendor/autoload.php';



/////////////////////////////////////////////////////
/// Helpers                                       ///
/////////////////////////////////////////////////////

function get_conf(): false | string {
  $conf_candidates = glob('conf/*.json');

  if (count($conf_candidates) !== 1) {
    return false;
  }
  return $conf_candidates[0];
}

function get_view_id(): false | string {
  if (!file_exists('conf/view_id.txt')) {
    return false;
  }

  return trim(file_get_contents('conf/view_id.txt'));
}

function add_month(string $date): string {
  return date('Y-m-d', strtotime($date . ' +1 month'));
}

function end_of_month(string $date): string {
  return date('Y-m-t', strtotime($date));
}

function write_csv_cell($fh, string $str) {
  fwrite($fh, '"' . str_replace('"', '""', $str) . '"' . ',');
}


/////////////////////////////////////////////////////
/// API Helpers                                   ///
/////////////////////////////////////////////////////

function initialize_analytics(string $conf): AnalyticsReporting {
  $client = new Google_Client();
  $client->setApplicationName("Hello Analytics Reporting");
  $client->setAuthConfig(__DIR__ . '/' . $conf);
  $client->setScopes(['https://www.googleapis.com/auth/analytics.readonly']);

  return new AnalyticsReporting($client);
}

function create_request(string $view_id, string $start, string $end, array $dimensions, array $metrics): ReportRequest {
  // Create the DateRange object.
  $dateRange = new DateRange([
    'startDate' => $start,
    'endDate' => $end,
  ]);

  // Create the ReportRequest object.
  $request = new ReportRequest();
  $request->setViewId($view_id);
  $request->setDateRanges($dateRange);
  $request->setMetrics($metrics);
  $request->setDimensions($dimensions);
  $request->setPageSize(10000);

  return $request;
}

function make_request(AnalyticsReporting $analytics, ReportRequest $request, int $page): GetReportsResponse {
  time_nanosleep(0, 100000000); // 100ms
  if ($page !== 0) {
    $request->setPageToken(strval($page));
  }
  return $analytics->reports->batchGet(new GetReportsRequest([
    'reportRequests' => [ $request ],
  ]));
}


/////////////////////////////////////////////////////
/// Results                                       ///
/////////////////////////////////////////////////////

function print_rowheaders($fh, $report) {
  $header = $report->getColumnHeader();
  $dimensionHeaders = $header->getDimensions();
  $metricHeaders = $header->getMetricHeader()->getMetricHeaderEntries();
  foreach ($dimensionHeaders as $dim) {
    write_csv_cell($fh, substr($dim, 3));
  }
  foreach ($metricHeaders as $metric) {
    write_csv_cell($fh, substr($metric->getName(), 3));
  }
  fwrite($fh, "\n");
}

function print_row($fh, object $row): void {
  foreach ($row->getDimensions() as $dim) {
    write_csv_cell($fh, $dim);
  }

  foreach ($row->getMetrics() as $metric) {
    foreach ($metric->getValues() as $value) {
      write_csv_cell($fh, $value);
    }
  }
  fwrite($fh, "\n");
}

function print_results(GetReportsResponse | null $response, $fh, bool $first): false | int {
  if ($response && $response[0]) {
    $report = $response[0];

    $rows = $report->getData()->getRows();
    if (empty($rows)) {
      return false;
    }
    if ($first) {
      print_rowheaders($fh, $report);
    }
    foreach ($rows as $row) {
      print_row($fh, $row);
    }
    return $report->getNextPageToken() ?: false;
  }
  return false;
}


/////////////////////////////////////////////////////
/// Set and validate arguments and configurations ///
/////////////////////////////////////////////////////

if (!isset($argv[1]) || !strtotime($argv[1])) {
  die("Usage: php demo.php <month>
  where month = YYYY-MM-DD\n");
}

$view_id = get_view_id();
if ($view_id === false) {
  die("Please put your view id in conf/view_id.txt\n");
}

$conf = get_conf();
if ($conf === false) {
  die("Please put your conf file in conf/<id>.json
  To create a config file, follow these instructions:
  https://developers.google.com/analytics/devguides/reporting/core/v4/quickstart/service-php\n

  Be warned that this process sucks ass.
  ");
}

if (!file_exists('data')) {
  mkdir('data');
}

$start = $argv[1];


/////////////////////////////////////////////////////
/// Dimensions and Metrics                        ///
/////////////////////////////////////////////////////

// Available dimensions and metrics can be found at https://ga-dev-tools.google/dimensions-metrics-explorer/
$dimensions = [
  new Dimension([ 'name' => 'ga:pagePath' ]),
  new Dimension([ 'name' => 'ga:date' ]),
  new Dimension([ 'name' => 'ga:fullReferrer' ]),
  new Dimension([ 'name' => 'ga:deviceCategory' ]),
  new Dimension([ 'name' => 'ga:city' ]),
  new Dimension([ 'name' => 'ga:region' ]),
  new Dimension([ 'name' => 'ga:country' ]),
];

$metrics = [
  new Metric([ 'expression' => "ga:sessions" ]),
  new Metric([ 'expression' => "ga:pageviews" ]),
];


/////////////////////////////////////////////////////
/// Main Loop                                     ///
/////////////////////////////////////////////////////

$analytics = initialize_analytics($conf);

while (strtotime($start) < time()) {
  $outfile = 'data/' . $start . '.csv';
  $end = end_of_month($start);

  echo "Starting {$start} - {$end}\n";

  $request = create_request($view_id, $start, $end, $dimensions, $metrics);
  $fh = fopen($outfile, 'w');
  for ($page = 0; $page !== false;) {
    echo "Requesting page $page\n";

    $response = make_request($analytics, $request, $page);
    $page = print_results($response, $fh, $page === 0);
  }
  fclose($fh);

  if (!filesize($outfile)) {
    unlink($outfile);
  }

  $start = add_month($start);
}
