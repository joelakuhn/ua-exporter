# UA Exporter

This is a small utility that exports data from Universal Analytics before it is deleted in June 2024.

## Setup

You will need to create an API service account, which is a giant pain in the ass, and all the documentation is out of date, but it *is* doable.

[https://developers.google.com/analytics/devguides/reporting/core/v4/quickstart/service-php](https://developers.google.com/analytics/devguides/reporting/core/v4/quickstart/service-php)

At the end of this process, you will have a JSON file that should be placed in the `conf` directory.

You will also need to add a view ID from which the data will be pulled. This view ID should go in `conf/view_id.txt`.

## Usage

Run the Utility with a given starting date. For performance and reliability, the tool queries one month at a time.

This will pull all data for a property from 2015 through the current date.

```shell
$ ./ua-exporter.php 2015-01-01
```
