-- Additive recurrence restrictions; NULL retains the existing unlimited schedule.
SET @event_until_exists=(SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='events' AND column_name='recurrence_until');
SET @event_recurrence_ddl=IF(@event_until_exists=0,'ALTER TABLE events ADD COLUMN recurrence_until DATE NULL DEFAULT NULL','SELECT 1');
PREPARE event_recurrence_stmt FROM @event_recurrence_ddl;
EXECUTE event_recurrence_stmt;
DEALLOCATE PREPARE event_recurrence_stmt;

SET @event_skips_exist=(SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='events' AND column_name='recurrence_skip_dates');
SET @event_recurrence_ddl=IF(@event_skips_exist=0,'ALTER TABLE events ADD COLUMN recurrence_skip_dates JSON NULL DEFAULT NULL','SELECT 1');
PREPARE event_recurrence_stmt FROM @event_recurrence_ddl;
EXECUTE event_recurrence_stmt;
DEALLOCATE PREPARE event_recurrence_stmt;
