-- MLP-279: проактив через очередь — тип job 'cron_spontaneous'. Идемпотентно.
ALTER TABLE `llm_jobs` MODIFY COLUMN `type` ENUM('mention','greeting','dynamic_command','cron_spontaneous') NOT NULL;
