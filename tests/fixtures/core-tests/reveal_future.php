<?php require 'base.php'; $YEAR = intval(date('Y')) + 1; $MONTH_START = 1; $DAY_START = 1; $MONTH_END = 12;
 $DOW = array('mon'=>'class','tue'=>'class','wed'=>'class','thu'=>'class','fri'=>'class','sat'=>'class','sun'=>'class'); $WEEKENDS = True; $OVERRIDE = array(); $REVEALHOUR = 0;
  require_once('calendar/calendar_main.php');
