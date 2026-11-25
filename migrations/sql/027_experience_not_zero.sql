-- 0 years of experience is no longer accepted; existing zeros become "not filled in".
UPDATE doctors SET experience_yrs = NULL WHERE experience_yrs = 0;
