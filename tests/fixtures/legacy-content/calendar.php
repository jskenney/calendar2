<?php
  # Course pages written for v4's front end (milestone 8): Bootstrap 3 and Skeleton
  # classes, glyphicons, inline jQuery, DataTables and Chart.js, and things Cal5 does
  # not provide (Bootstrap's JavaScript, Font Awesome, links to v4's own files), so
  # bin/cal5 audit has something to report.  Dates are long past (2018).

  $OVERRIDE = array();
  $COMBINE = array();
  $MONTH_START = 8;
  $DAY_START = 20;
  $MONTH_END = 9;
  $YEAR = 2018;
  $DOW = array('mon' => 'class', 'thu' => 'lab');
  $COURSE = 'LC101';
  $COURSENAME = 'Legacy Content';
  $PAGE_TITLE = 'LC101';
  $WEEKENDS = False;
  $ADMIN = 'legacypass';
  $SECRET = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';
  $DEFAULT_TODAYS_LECTURE = False;
  $ACCESS_FILE = 'virtual/cal4.access';
  $CLASS_FILE = 'virtual/cal4.virtual';
  $BOX = array(0 => 'title', 1 => 'homework');
  $HTML = array('title' => 'index.html', 'homework' => 'homework.html');
  $LINK = array();
  $PDF = array();
  $PPT = array();
  $SRC = array();
  $CUSTOMJS = array('lab' => array('docs/course.js'));
  $IGNORE_A_TAG_WARNING = True;

  require_once('calendar/calendar_main.php');
