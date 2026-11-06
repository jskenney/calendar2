<?php
  # Edge cases for the schedule and content scanner (milestone 3).
  # Spring 2019 (in the past, so v4's results never change).

  $OVERRIDE = array(1 => array(8 => 'exam',          # before $DAY_START: ignored
                               17 => 'Lecture'),     # same type as the weekly plan, but forced
                    2 => array(14 => 'Snow Day',     # snow-day/ exists but has no events
                               29 => 'exam'),        # 2019 is not a leap year
                    3 => array(31 => 'exam'));       # a Sunday
  $COMBINE = array('lecture' => array(2 => array('lab', 'lab'), 4 => array('Studio Time')),
                   'lab' => array(1 => array('lecture')));
  $MONTH_START = 1;
  $DAY_START = 9;
  $MONTH_END = 3;
  $YEAR = 2019;
  $DOW = array('tue' => 'Lecture', 'thu' => 'lab', 'fri' => 'Studio Time');
  $COURSE = 'EDGE1';
  $COURSENAME = 'Edge Cases';
  $PAGE_TITLE = 'EDGE1 (Spring 2019)';
  $DEFAULT_TODAYS_LECTURE = False;
  $WEEKENDS = False;
  $ADMIN = 'edgepass';
  $SECRET = 'abcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdef1234';
  $ACCESS_FILE = 'virtual/cal4.access';
  $CLASS_FILE = 'virtual/cal4.virtual';
  $BOX = array(0 => 'title', 1 => 'notes', 2 => 'homework');
  $HTML = array('title' => 'index.html', 'homework' => 'homework.html');
  $LINK = array();
  $PDF = array('title' => 'slides.pdf', 'homework' => 'homework.pdf');
  $PPT = array();
  $SRC = array('notes' => 'notes.txt', 'homework' => 'homework.txt');
  $IGNORE_A_TAG_WARNING = True;

  require_once('calendar/calendar_main.php');
