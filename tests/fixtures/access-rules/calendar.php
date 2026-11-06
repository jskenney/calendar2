<?php
  # Every v4 release-rule form and every step of the rule precedence (milestone 4).
  # Every outcome is fixed: dates are long past (2018) or far in the future (2099, or
  # +9999 days = January 2046), so v4's results do not depend on when the tests run
  # (until 2046).

  $OVERRIDE = array(9 => array(5 => 'exam', 7 => 'exam'));
  $COMBINE = array();
  $MONTH_START = 8;
  $DAY_START = 20;
  $MONTH_END = 9;
  $YEAR = 2018;
  $DOW = array('mon' => 'class', 'wed' => 'lab', 'fri' => 'lab');
  $COURSE = 'AR101';
  $COURSENAME = 'Access Rules';
  $PAGE_TITLE = 'AR101';
  $WEEKENDS = False;
  $ADMIN = 'rulespass';
  $SECRET = '9876543210abcdef9876543210abcdef9876543210abcdef9876543210abcdef';
  $DEFAULT_TODAYS_LECTURE = False;
  $ACCESS_FILE = 'virtual/cal4.access';
  $CLASS_FILE = 'virtual/cal4.virtual';
  $BOX = array(0 => 'title', 1 => 'notes', 2 => 'reading', 3 => 'homework', 4 => 'homework-solution');
  $HTML = array('title' => 'index.html', 'notes' => 'notes.html', 'reading' => 'reading.html',
                'homework' => 'homework.html', 'homework-solution' => 'answers.html');
  $LINK = array();
  $PDF = array();
  $PPT = array();
  $SRC = array();
  $IGNORE_A_TAG_WARNING = True;

  require_once('calendar/calendar_main.php');
