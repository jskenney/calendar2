<?php
  # Kitchen-sink v4 configuration: sets every variable calendar-core 4.6 reads.
  # The semester is in the past (2018) so v4's date-based results never change.

  $OVERRIDE = array(9  => array(3 => 'HOLIDAY', 28 => 'Exam'),
                    10 => array(8 => 'monday schedule'),
                    12 => array(14 => 'Final Exam'));
  $COMBINE = array('class' => array(3 => array('lab')));
  $MONTH_START = 8;
  $DAY_START = 20;
  $MONTH_END = 12;
  $YEAR = 2018;
  $DOW = array('mon' => 'class', 'wed' => 'lab', 'fri' => 'class');
  $COURSE = 'KS101';
  $COURSENAME = 'Kitchen Sink & Co';
  $COURSENAMEFULL = 'KS101: Kitchen Sink & Co (Fall 2018)';
  $PAGE_TITLE = "$COURSE (Fall $YEAR)";
  $PAGE_MODIFY = array('classyear' => '19', 'server' => 'ks.example.edu', 'on' => 'KS-replace-on');
  $CUSTOMCSS = array('class' => array('docs/class.css', 'docs/missing.css'));
  $CUSTOMJS = array('lab' => array('docs/lab.js'));
  $CUSTOMCSS_CAL = 'docs/cal.css';
  $NAVBAR_DROPDOWNS = array('class' => 'glyphicon-apple', 'lab' => 'glyphicon-knight');
  $NAVBAR_DROPDOWNS_INSTRUCTOR = array('class' => 'glyphicon-apple', 'lab' => 'glyphicon-knight',
                                       'exam' => 'glyphicon-hourglass', 'final-exam' => 'glyphicon-education');
  $WEEKENDS = False;
  $HIDE_WEEKS = True;
  $HIDE_BEGINNING_DAY = 20;
  $HIDE_ENDING_DAY = 14;
  $ACCESS_LOG = 'logs/access.log';
  $ADMIN = 'kitchenpass';
  $LOGON_TIME = 3600;
  $SECRET = 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210';
  # Kept False here: v4 matches "today" by month and day only (ignoring the year),
  # so True would make the start page depend on the date the tests run.
  $DEFAULT_TODAYS_LECTURE = False;
  $CALENDAR_TOOL = 'calendar_display_alt';
  $ACCESS_FILE = 'virtual/cal4.access';
  $CLASS_FILE = 'virtual/cal4.virtual';
  $BOX = array(0 => 'title', 1 => 'instructor', 2 => 'reading', 3 => 'notes',
               4 => 'homework', 5 => 'homework-solution', 6 => 'slides');
  $HTML = array('title' => 'index.html', 'homework' => 'homework.html', 'reading' => 'reading.html',
                'homework-solution' => 'answers.html', 'instructor' => 'instructor.html');
  $LINK = array('title' => 'lindex.html', 'reading' => 'lreading.html');
  $PDF = array('title' => 'slides.pdf', 'homework' => 'homework.pdf', 'reading' => 'reading.pdf');
  $PPT = array('title' => 'slides.pptx', 'slides' => 'deck.ppt');
  $SRC = array('notes' => 'notes.txt', 'homework' => 'homework.txt');
  $MD = array('title' => 'index.md', 'notes' => 'notes.md');
  $MARKDOWN_MENU_LEVELS = array(2, 3);
  $IGNORE_A_TAG_WARNING = True;
  $LOCK = 'LOCKED';            # the site is locked only while this file exists (it does not)
  $REVEALHOUR = 12;
  $LOAD_PAGES = array('syllabus');
  $ALLOWED_PATHS = array('shared');
  $DEBUG_MODE = True;

  require_once('calendar/calendar_main.php');
