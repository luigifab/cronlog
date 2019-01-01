<?php
/**
 * Created S/31/05/2014
 * Updated L/16/07/2018
 *
 * Copyright 2012-2019 | Fabrice Creuzot (luigifab) <code~luigifab~fr>
 * https://www.luigifab.fr/magento/cronlog
 *
 * This program is free software, you can redistribute it or modify
 * it under the terms of the GNU General Public License (GPL) as published
 * by the free software foundation, either version 2 of the license, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but without any warranty, without even the implied warranty of
 * merchantability or fitness for a particular purpose. See the
 * GNU General Public License (GPL) for more details.
 */

class Luigifab_Cronlog_Model_Rewrite_Cron extends Mage_Cron_Model_Observer {

	protected function _generateJobs($jobs, $exists) {

		$scheduleAheadFor = Mage::getStoreConfig(self::XML_PATH_SCHEDULE_AHEAD_FOR) * 60;
		$schedule = Mage::getModel('cron/schedule');

		foreach ($jobs as $jobCode => $jobConfig) {

			$cronExpr = null;

			if ($jobConfig->schedule->config_path)
				$cronExpr = Mage::getStoreConfig((string) $jobConfig->schedule->config_path);

			if (empty($cronExpr) && $jobConfig->schedule->cron_expr)
				$cronExpr = (string) $jobConfig->schedule->cron_expr;

			// une tâche CRON peut être désactivée soit dans le config.xml, soit dans la configuration
			// (config.xml/configuration : attention la configuration n'est pas fusionnée)
			// - config.xml, Mage::getConfig()->getNode('crontab/jobs[/../schedule/disabled]')
			// - configuration, Mage::getConfig()->getNode('default/crontab/jobs[/../schedule/disabled]')
			if (!$cronExpr || !empty($jobConfig->schedule->disabled) ||
			    !empty(Mage::getStoreConfig('crontab/jobs/'.$jobCode.'/schedule/disabled')))
				continue;

			$now = time();
			$timeAhead = $now + $scheduleAheadFor;

			$schedule->setCronExpr($cronExpr);
			$schedule->setData('job_code', $jobCode);
			$schedule->setData('status', Mage_Cron_Model_Schedule::STATUS_PENDING);

			for ($time = $now; $time < $timeAhead; $time += 60) {

				$ts = strftime('%Y-%m-%d %H:%M:00', $time);

				// already scheduled
				if (!empty($exists[$jobCode.'/'.$ts]))
					continue;
				// time does not match cron expression
				if (!$schedule->trySchedule($time))
					continue;

				$schedule->unsScheduleId()->save();
			}
		}

		return $this;
	}

	public function cleanup() {

		$val = intval(Mage::getStoreConfig('cronlog/general/lifetime'));
		if ($val < 7200) // 5 jours
			return parent::cleanup();

		// check every 24 hours if history cleanup is needed
		if (Mage::app()->loadCache(self::CACHE_KEY_LAST_HISTORY_CLEANUP_AT) > (time() - 1440 * 60))
			return $this;

		$jobs = Mage::getResourceModel('cron/schedule_collection');
		$jobs->addFieldToFilter('status', array('eq' => 'success'));
		$jobs->addFieldToFilter('scheduled_at', array('lt' => new Zend_Db_Expr('DATE_SUB(UTC_TIMESTAMP(), INTERVAL '.$val.' MINUTE)')));

		foreach ($jobs as $job)
			$job->delete();

		$jobs = Mage::getResourceModel('cron/schedule_collection');
		$jobs->addFieldToFilter('status', array('neq' => 'success'));
		$jobs->addFieldToFilter('scheduled_at', array('lt' => new Zend_Db_Expr('DATE_SUB(UTC_TIMESTAMP(), INTERVAL '.(3 * $val).' MINUTE)')));

		foreach ($jobs as $job)
			$job->delete();

		Mage::app()->saveCache(time(), self::CACHE_KEY_LAST_HISTORY_CLEANUP_AT, array('crontab'), null);
		return $this;
	}

	public function specialCheckRewrite() {
		return true;
	}
}