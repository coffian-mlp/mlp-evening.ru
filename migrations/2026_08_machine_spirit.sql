-- MLP-300: «дух машины» — новый тип задачи в очереди LLM.
-- Расширение ENUM аддитивно: существующие строки не затрагиваются.

ALTER TABLE `llm_jobs`
  MODIFY `type` ENUM('mention','greeting','dynamic_command','cron_spontaneous','machine_spirit') NOT NULL;
