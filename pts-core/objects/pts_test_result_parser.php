<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2010 - 2017, Phoronix Media
	Copyright (C) 2010 - 2017, Michael Larabel

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

class pts_test_result_parser
{
	private static $supported_sensors = null;
	private static $monitoring_sensors = array();

	protected static function setup_parse_xml_file(&$test_profile)
	{
		$parse_xml_file = $test_profile->get_file_parser_spec();

		if($parse_xml_file == false)
		{
			return false;
		}

		$xml_options = LIBXML_COMPACT | LIBXML_PARSEHUGE;
		return simplexml_load_file($parse_xml_file, 'SimpleXMLElement', $xml_options);
	}
	public static function system_monitor_task_check(&$test_profile)
	{
		$xml = self::setup_parse_xml_file($test_profile);

		if($xml === false)
		{
			return false;
		}

		self::$monitoring_sensors = array();
		$test_directory = $test_profile->get_install_dir();

		if($xml->SystemMonitor)
		{
			foreach($xml->SystemMonitor as $sys_monitor)
			{
				$sensor = isset($sys_monitor->Sensor) ? $sys_monitor->Sensor->__toString() : null;
				$polling_freq = isset($sys_monitor->PollingFrequency) ? $sys_monitor->PollingFrequency->__toString() : null;
				$report_as = isset($sys_monitor->Report) ? $sys_monitor->Report->__toString() : null;

				// TODO: Right now we are looping through SystemMonitor tags, but right now pts-core only supports providing one monitor sensor as the result
				$sensor = explode('.', $sensor);

				if($sensor == array('sys', 'time'))
				{
					// sys.time is a special case since we are just timing the test length and thus don't need to fork the thread
					$start_time = microtime(true);
					self::$monitoring_sensors[] = array(0, $sensor, null, $start_time);
					continue;
				}

				if(self::$supported_sensors == null)
				{
					// Cache this since this shouldn't change between tests/runs
					self::$supported_sensors = phodevi::supported_sensors();
				}

				if(count($sensor) != 2 || !in_array($sensor, self::$supported_sensors))
				{
					// Not a sensor or it's not supported
					continue;
				}

				if(!is_numeric($polling_freq) || $polling_freq < 0.5)
				{
					// No polling frequency supplied
					continue;
				}

				$polling_freq *= 1000000; // Convert from seconds to micro-seconds

				if(!in_array($report_as, array('ALL', 'MAX', 'MIN', 'AVG')))
				{
					// Not a valid reporting type
					continue;
				}

				if(!function_exists('pcntl_fork'))
				{
					pts_client::$display->test_run_instance_error('PHP with PCNTL support enabled is required for this test.');
					return false;
				}

				$monitor_file = tempnam($test_directory, '.monitor');
				$pid = pcntl_fork();

				if($pid != -1)
				{
					if($pid)
					{
						// Main process still
						self::$monitoring_sensors[] = array($pid, $sensor, $report_as, $monitor_file);
						continue;
					}
					else
					{
						$sensor_values = array();

						while(is_file($monitor_file)) // when test ends, it will remove file in case the posix_kill doesn't happen
						{
							$sensor_values[] = phodevi::read_sensor($sensor);
							file_put_contents($monitor_file, implode("\n", $sensor_values));
							usleep($polling_freq);
						}

						exit(0);
					}
				}
			}
		}

		return count(self::$monitoring_sensors) > 0;
	}
	public static function system_monitor_task_post_test(&$test_profile)
	{
		$test_directory = $test_profile->get_install_dir();

		foreach(self::$monitoring_sensors as $sensor_r)
		{
			if($sensor_r[1] == array('sys', 'time'))
			{
				// sys.time is a special case
				$end_time = microtime(true);

				// Delta time
				$result_value = $end_time - $sensor_r[3];

				$minimal_test_time = pts_config::read_user_config('PhoronixTestSuite/Options/TestResultValidation/MinimalTestTime', 3);
				if($result_value < $minimal_test_time)
				{
					// The test ended too fast
					$result_value = null;
				}
			}
			else
			{
				// Kill the sensor monitoring thread
				if(function_exists('posix_kill') == false)
				{
					pts_client::$display->test_run_error('The PHP POSIX extension is required for this test.');
					return false;
				}

				posix_kill($sensor_r[0], SIGTERM);

				$sensor_values = explode("\n", pts_file_io::file_get_contents($sensor_r[3]));
				pts_file_io::unlink($sensor_r[3]);

				if(count($sensor_values) == 0)
				{
					continue;
				}

				switch($sensor_r[2])
				{
					case 'MAX':
						$result_value = max($sensor_values);
						break;
					case 'MIN':
						$result_value = min($sensor_values);
						break;
					case 'AVG':
						$result_value = array_sum($sensor_values) / count($sensor_values);
						break;
					case 'ALL':
						$result_value = implode(',', $sensor_values);
						break;
					default:
						$result_value = null;
						break;
				}
			}

			if($result_value != null)
			{
				// For now it's only possible to return one result per test
				return $result_value;
			}
		}

		return false;
	}
	public static function parse_result(&$test_run_request, $test_log_file)
	{
		if($test_run_request->test_profile->get_file_parser_spec() == false)
		{
			return null;
		}

		$test_identifier = $test_run_request->test_profile->get_identifier();
		$extra_arguments = $test_run_request->get_arguments();
		$pts_test_arguments = trim($test_run_request->test_profile->get_default_arguments() . ' ' . str_replace($test_run_request->test_profile->get_default_arguments(), '', $extra_arguments) . ' ' . $test_run_request->test_profile->get_default_post_arguments());

		switch($test_run_request->test_profile->get_display_format())
		{
			case 'IMAGE_COMPARISON':
				$test_run_request->active->active_result = self::parse_iqc_result($test_run_request->test_profile, $test_log_file, $pts_test_arguments, $extra_arguments);
				break;
			case 'PASS_FAIL':
			case 'MULTI_PASS_FAIL':
				$test_run_request->active->active_result = self::parse_generic_result($test_run_request, $test_log_file, $pts_test_arguments, $extra_arguments);
				if(str_replace(array('PASS', 'FAIL', ','), null, $test_run_request->active->active_result) == null)
				{
					// properly formatted multi-pass fail
				}
				else if($test_run_request->active->active_result == 'TRUE' || $test_run_request->active->active_result == 'PASS' || $test_run_request->active->active_result == 'PASSED')
				{
					$test_run_request->active->active_result = 'PASS';
				}
				else
				{
					$test_run_request->active->active_result = 'FAIL';
				}
				break;
			case 'BAR_GRAPH':
			default:
				$test_run_request->active->active_result = self::parse_numeric_result($test_run_request, $test_log_file, $pts_test_arguments, $extra_arguments);

				if($test_run_request->test_profile->get_display_format() == 'BAR_GRAPH' && !is_numeric($test_run_request->active->active_result))
				{
					$test_run_request->active->active_result = false;
				}
				else
				{
					$test_run_request->active->active_min_result = self::parse_numeric_result($test_run_request, $test_log_file, $pts_test_arguments, $extra_arguments, 'MIN');
					$test_run_request->active->active_max_result = self::parse_numeric_result($test_run_request, $test_log_file, $pts_test_arguments, $extra_arguments, 'MAX');
				}
				break;
		}
	}
	public static function generate_extra_data(&$test_result, &$test_log_file = null)
	{
		$xml = self::setup_parse_xml_file($test_result->test_profile);

		if($xml === false)
		{
			return false;
		}

		$extra_results = array();
		if($xml->ExtraData)
		{
			foreach($xml->ExtraData as $entry)
			{
				$id = isset($entry->Identifier) ? $entry->Identifier->__toString() : null;

				$frame_all_times = array();

				switch($id)
				{
					case 'libframetime-output':
						// libframetime output
						$line_values = explode(PHP_EOL, file_get_contents($test_log_file));
						if(!empty($line_values) && isset($line_values[3]))
						{
							foreach($line_values as &$v)
							{
								if(substr($v, -3) == ' us' && substr($v, 0, 10) == 'Frametime ')
								{
									$frametime = substr($v, 10);
									$frametime = substr($frametime, 0, -3);
									if($frametime > 2000)
									{
										$frametime = $frametime / 1000;
										$frame_all_times[] = $frametime;
									}
								}
							}
							$frame_all_times = pts_math::remove_outliers($frame_all_times);
						}
						break;
					case 'csv-dump-frame-latencies':
						// Civ Beyond Earth
						$csv_values = explode(',', pts_file_io::file_get_contents($test_log_file));
						if(!empty($csv_values) && isset($csv_values[3]))
						{
							foreach($csv_values as $i => &$v)
							{
								if(!is_numeric($v))
								{
									unset($csv_values[$i]);
									continue;
								}

								$frame_all_times[] = $v;
							}
						}
						break;
					case 'com-speeds-frame-latency-totals':
						// id Tech Games
						$log_file = pts_file_io::file_get_contents($test_log_file);
						$frame_all_times = array();
						while(($log_file = strstr($log_file, 'frame:')))
						{
							if(($a = strpos($log_file, ' all: ')) !== false && $a < strpos($log_file, "\n"))
							{
								$all = ltrim(substr($log_file, $a + 6));
								$all = substr($all, 0, strpos($all, ' '));

								if(is_numeric($all) && $all > 0)
								{
									$frame_all_times[] = $all;
								}
							}
							$log_file = strstr($log_file, 'bk:');
						}
						break;
				}

				if(isset($frame_all_times[60]))
				{
					// Take off the first frame as it's likely to have taken much longer when game just starting off...
					array_shift($frame_all_times);
					$tp = clone $test_result->test_profile;
					$tp->set_result_scale('Milliseconds');
					$tp->set_result_proportion('LIB');
					$tp->set_display_format('LINE_GRAPH');
					$tp->set_identifier(null);
					$extra_result = new pts_test_result($tp);
					$extra_result->active = new pts_test_result_buffer_active();
					$extra_result->set_used_arguments_description('Total Frame Time');
					$extra_result->active->set_result(implode(',', $frame_all_times));
					$extra_results[] = $extra_result;
					//$extra_result->set_used_arguments(phodevi::sensor_name($sensor) . ' ' . $test_result->get_arguments());
				}
			}
		}

		if(!empty($extra_results))
		{
			$test_result->secondary_linked_results = $extra_results;
		}
	}
	public static function calculate_end_result(&$test_result, &$active_result_buffer)
	{
		$trial_results = $active_result_buffer->results;

		if(count($trial_results) == 0)
		{
			$test_result->active->set_result(0);
			return false;
		}

		$END_RESULT = 0;

		switch($test_result->test_profile->get_display_format())
		{
			case 'NO_RESULT':
				// Nothing to do, there are no results
				break;
			case 'LINE_GRAPH':
			case 'FILLED_LINE_GRAPH':
			case 'TEST_COUNT_PASS':
				// Just take the first result
				$END_RESULT = $trial_results[0];
				break;
			case 'IMAGE_COMPARISON':
				// Capture the image
				$iqc_image_png = $trial_results[0];

				if(is_file($iqc_image_png))
				{
					$img_file_64 = base64_encode(file_get_contents($iqc_image_png, FILE_BINARY));
					$END_RESULT = $img_file_64;
					unlink($iqc_image_png);				
				}
				break;
			case 'PASS_FAIL':
			case 'MULTI_PASS_FAIL':
				// Calculate pass/fail type
				$END_RESULT = -1;

				if(count($trial_results) == 1)
				{
					$END_RESULT = $trial_results[0];
				}
				else
				{
					foreach($trial_results as $result)
					{
						if($result == 'FALSE' || $result == '0' || $result == 'FAIL' || $result == 'FAILED')
						{
							if($END_RESULT == -1 || $END_RESULT == 'PASS')
							{
								$END_RESULT = 'FAIL';
							}
						}
						else
						{
							if($END_RESULT == -1)
							{
								$END_RESULT = 'PASS';
							}
						}
					}
				}
				break;
			case 'BAR_GRAPH':
			default:
				// Result is of a normal numerical type
				switch($test_result->test_profile->get_result_quantifier())
				{
					case 'MAX':
						$END_RESULT = max($trial_results);
						break;
					case 'MIN':
						$END_RESULT = min($trial_results);
						break;
					case 'AVG':
					default:
						// assume AVG (average)
						$is_float = false;
						$TOTAL_RESULT = 0;
						$TOTAL_COUNT = 0;

						foreach($trial_results as $result)
						{
							$result = trim($result);

							if(is_numeric($result))
							{
								$TOTAL_RESULT += $result;
								$TOTAL_COUNT++;

								if(!$is_float && strpos($result, '.') !== false)
								{
									$is_float = true;
								}
							}
						}

						$END_RESULT = pts_math::set_precision($TOTAL_RESULT / ($TOTAL_COUNT > 0 ? $TOTAL_COUNT : 1), $test_result->get_result_precision());

						if(!$is_float)
						{
							$END_RESULT = round($END_RESULT);
						}

						if(count($min = $active_result_buffer->min_results) > 0)
						{
							$min = round(min($min), 2);

							if($min < $END_RESULT && is_numeric($min) && $min != 0)
							{
								$test_result->active->set_min_result($min);
							}
						}
						if(count($max = $active_result_buffer->max_results) > 0)
						{
							$max = round(max($max), 2);

							if($max > $END_RESULT && is_numeric($max) && $max != 0)
							{
								$test_result->active->set_max_result($max);
							}
						}
						break;
				}
				break;
		}

		$test_result->active->set_result($END_RESULT);
	}
	protected static function parse_iqc_result(&$test_profile, $log_file, $pts_test_arguments, $extra_arguments)
	{
		$xml = self::setup_parse_xml_file($test_profile);

		if($xml === false)
		{
			return false;
		}

		if(!extension_loaded('gd'))
		{
			// Needs GD library to work
			return false;
		}

		$test_result = false;

		if($xml->ImageParser)
		{
			foreach($xml->ImageParser as $entry)
			{
				$match_test_arguments = isset($entry->MatchToTestArguments) ? $entry->MatchToTestArguments->__toString() : null;
				if(!empty($match_test_arguments) && strpos($pts_test_arguments, $match_test_arguments) === false)
				{
					// This is not the ResultsParser XML section to use as the MatchToTestArguments does not match the PTS test arguments
					continue;
				}

				$iqc_source_file = isset($entry->SourceImage) ? $entry->SourceImage->__toString() : null;
				if(is_file($test_profile->get_install_dir() . $iqc_source_file))
				{
					$iqc_source_file = $test_profile->get_install_dir() . $iqc_source_file;
				}
				else
				{
					// No image file found
					continue;
				}

				$img = pts_image::image_file_to_gd($iqc_source_file);
				if($img == false)
				{
					return;
				}

				$iqc_image_x = isset($entry->ImageX) ? $entry->ImageX->__toString() : null;
				$iqc_image_y = isset($entry->ImageY) ? $entry->ImageY->__toString() : null;
				$iqc_image_width = isset($entry->ImageWidth) ? $entry->ImageWidth->__toString() : null;
				$iqc_image_height = isset($entry->ImageHeight) ? $entry->ImageHeight->__toString() : null;
				$img_sliced = imagecreatetruecolor($iqc_image_width, $iqc_image_height);
				imagecopyresampled($img_sliced, $img, 0, 0, $iqc_image_x, $iqc_image_y, $iqc_image_width, $iqc_image_height, $iqc_image_width, $iqc_image_height);
				$test_result = $test_profile->get_install_dir() . 'iqc.png';
				imagepng($img_sliced, $test_result);

				if($test_result != false)
				{
					break;
				}
			}
		}

		return $test_result;
	}
	protected static function parse_numeric_result(&$test_run_request, $log_file, $pts_test_arguments, $extra_arguments, $prefix = null)
	{
		return self::parse_result_process($test_run_request, $log_file, $pts_test_arguments, $extra_arguments, true, $prefix);
	}
	protected static function parse_generic_result(&$test_run_request, $log_file, $pts_test_arguments, $extra_arguments, $prefix = null)
	{
		return self::parse_result_process($test_run_request, $log_file, $pts_test_arguments, $extra_arguments, false, $prefix);
	}
	protected static function parse_result_process(&$test_run_request, $log_file, $pts_test_arguments, $extra_arguments, $is_numeric_check = true, $prefix = null)
	{
		$xml = self::setup_parse_xml_file($test_run_request->test_profile);
		$test_result = false;

		$test_identifier = $test_run_request->test_profile->get_identifier();
		if($prefix != null && substr($prefix, -1) != '_')
		{
			$prefix .= '_';
		}

		if($xml->ResultsParser)
		{
			foreach($xml->ResultsParser as $entry)
			{
				$match_test_arguments = isset($entry->MatchToTestArguments) ? $entry->MatchToTestArguments->__toString() : null;
				$scale = isset($entry->ResultScale) ? $entry->ResultScale->__toString() : null;
				$proportion = isset($entry->ResultProportion) ? $entry->ResultProportion->__toString() : null;
				$precision = isset($entry->ResultPrecision) ? $entry->ResultPrecision->__toString() : null;
				$template = isset($entry->OutputTemplate) ? $entry->OutputTemplate->__toString() : null;
				$key = isset($entry->ResultKey) ? $entry->ResultKey->__toString() : null;
				$line_hint = isset($entry->LineHint) ? $entry->LineHint->__toString() : null;
				$line_before_hint = isset($entry->LineBeforeHint) ? $entry->LineBeforeHint->__toString() : null;
				$line_after_hint = isset($entry->LineAfterHint) ? $entry->LineAfterHint->__toString() : null;
				$before_string = isset($entry->ResultBeforeString) ? $entry->ResultBeforeString->__toString() : null;
				$after_string = isset($entry->ResultAfterString) ? $entry->ResultAfterString->__toString() : null;
				$divide_by = isset($entry->DivideResultBy) ? $entry->DivideResultBy->__toString() : null;
				$multiply_by = isset($entry->MultiplyResultBy) ? $entry->MultiplyResultBy->__toString() : null;
				$strip_from_result = isset($entry->StripFromResult) ? $entry->StripFromResult->__toString() : null;
				$strip_result_postfix = isset($entry->StripResultPostfix) ? $entry->StripResultPostfix->__toString() : null;
				$multi_match = isset($entry->MultiMatch) ? $entry->MultiMatch->__toString() : null;
				$file_format = isset($entry->FileFormat) ? $entry->FileFormat->__toString() : null;

				if(!empty($match_test_arguments) && strpos($pts_test_arguments, $match_test_arguments) === false)
				{
					// This is not the ResultsParser XML section to use as the MatchToTestArguments does not match the PTS test arguments
					continue;
				}

				if($key == null)
				{
					$key = '#_' . $prefix . 'RESULT_#';
				}
				else
				{
					switch($key)
					{
						case 'PTS_TEST_ARGUMENTS':
							$key = '#_' . $prefix . str_replace(' ', '', $pts_test_arguments) . '_#';
							break;
						case 'PTS_USER_SET_ARGUMENTS':
							$key = '#_' . $prefix . str_replace(' ', '', $extra_arguments) . '_#';
							break;
					}
				}

				// The actual parsing here
				$start_result_pos = strrpos($template, $key);

				if($prefix != null && $start_result_pos === false && $template != 'csv-dump-frame-latencies' && $template != 'libframetime-output')
				{
					// XXX: technically the $prefix check shouldn't be needed, verify whether safe to have this check be unconditional on start_result_pos failing...
					return false;
				}

				$space_out_chars = array('(', ')', "\t");

				if(isset($file_format) && $file_format == 'CSV')
				{
					$space_out_chars[] = ',';
				}

				if((isset($template[($start_result_pos - 1)]) && $template[($start_result_pos - 1)] == '/') || (isset($template[($start_result_pos + strlen($key))]) && $template[($start_result_pos + strlen($key))] == '/'))
				{
					$space_out_chars[] = '/';
				}

				$end_result_pos = $start_result_pos + strlen($key);
				$end_result_line_pos = strpos($template, "\n", $end_result_pos);
				$template_line = substr($template, 0, ($end_result_line_pos === false ? strlen($template) : $end_result_line_pos));
				$template_line = substr($template_line, strrpos($template_line, "\n"));
				$template_r = explode(' ', pts_strings::trim_spaces(str_replace($space_out_chars, ' ', str_replace('=', ' = ', $template_line))));
				$template_r_pos = array_search($key, $template_r);

				if($template_r_pos === false)
				{
					// Look for an element that partially matches, if like a '.' or '/sec' or some other pre/post-fix is present
					foreach($template_r as $x => $r_check)
					{
						if(isset($key[$x]) && strpos($r_check, $key[$x]) !== false)
						{
							$template_r_pos = $x;
							break;
						}
					}
				}

				$search_key = null;
				$line_before_key = null;

				if(isset($line_hint) && $line_hint != null && strpos($template_line, $line_hint) !== false)
				{
					$search_key = $line_hint;
				}
				else if(isset($line_before_hint) && $line_before_hint != null && strpos($template, $line_hint) !== false)
				{
					$search_key = null; // doesn't really matter what this value is
				}
				else if(isset($line_after_hint) && $line_after_hint != null && strpos($template, $line_hint) !== false)
				{
					$search_key = null; // doesn't really matter what this value is
				}
				else
				{
					foreach($template_r as $line_part)
					{
						if(strpos($line_part, ':') !== false && strlen($line_part) > 1)
						{
							// add some sort of && strrpos($template, $line_part)  to make sure there isn't two of the same $search_key
							$search_key = $line_part;
							break;
						}
					}

					if($search_key == null && isset($key))
					{
						// Just try searching for the first part of the string
						/*
						for($i = 0; $i < $template_r_pos; $i++)
						{
							$search_key .= $template_r . ' ';
						}
						*/

						// This way if there are ) or other characters stripped, the below method will work where the above one will not
						$search_key = substr($template_line, 0, strpos($template_line, $key));
					}
				}

				if(is_file($log_file))
				{
					$output = file_get_contents($log_file);
				}
				else
				{
					// Nothing to parse
					return false;
				}

				$test_results = array();
				$already_processed = false;
				$frame_time_values = null;

				if($template == 'libframetime-output')
				{
					$already_processed = true;
					$frame_time_values = array();
					$line_values = explode(PHP_EOL, $output);
					if(!empty($line_values) && isset($line_values[3]))
					{
						foreach($line_values as &$v)
						{
							if(substr($v, -3) == ' us' && substr($v, 0, 10) == 'Frametime ')
							{
								$frametime = substr($v, 10);
								$frametime = substr($frametime, 0, -3);
								if($frametime > 2000)
								{
									$frametime = $frametime / 1000;
									$frame_time_values[] = $frametime;
								}
							}
						}
						$frame_time_values = pts_math::remove_outliers($frame_time_values);
					}
				}
				else if($template == 'csv-dump-frame-latencies')
				{
					$already_processed = true;
					$frame_time_values = explode(',', $output);
				}

				if(!empty($frame_time_values) && isset($frame_time_values[3]))
				{
					// Get rid of the first value
					array_shift($frame_time_values);

					foreach($frame_time_values as $f => &$v)
					{
						if(!is_numeric($v) || $v == 0)
						{
							unset($frame_time_values[$f]);
							continue;
						}
						$v = 1000 / $v;
					}

					switch($prefix)
					{
						case 'MIN_':
							$val = min($frame_time_values);
							break;
						case 'MAX_':
							$val = max($frame_time_values);
							break;
						case 'AVG_':
							default:
							$val = array_sum($frame_time_values) / count($frame_time_values);
							break;
					}

					if($val != 0)
					{
						$test_results[] = $val;
					}
				}

				if(($search_key != null || (isset($line_before_hint) && $line_before_hint != null) || (isset($line_after_hint) && $line_after_hint) != null || (isset($key) && $template_r[0] == $key)) && $already_processed == false)
				{
					$is_multi_match = !empty($multi_match) && $multi_match != 'NONE';

					do
					{
						$count = count($test_results);

						if($line_before_hint != null)
						{
							pts_client::test_profile_debug_message('Result Parsing Line Before Hint: ' . $line_before_hint);
							$line = substr($output, strpos($output, "\n", strrpos($output, $line_before_hint)));
							$line = substr($line, 0, strpos($line, "\n", 1));
							$output = substr($output, 0, strrpos($output, "\n", strrpos($output, $line_before_hint))) . "\n";
						}
						else if($line_after_hint != null)
						{
							pts_client::test_profile_debug_message('Result Parsing Line After Hint: ' . $line_after_hint);
							$line = substr($output, 0, strrpos($output, "\n", strrpos($output, $line_before_hint)));
							$line = substr($line, strrpos($line, "\n", 1) + 1);
							$output = null;
						}
						else if($search_key != null)
						{
							$search_key = trim($search_key);
							pts_client::test_profile_debug_message('Result Parsing Search Key: ' . $search_key);
							$line = substr($output, 0, strpos($output, "\n", strrpos($output, $search_key)));
							$start_of_line = strrpos($line, "\n");
							$output = substr($line, 0, $start_of_line) . "\n";
							$line = substr($line, $start_of_line + 1);
						}
						else
						{
							// Condition $template_r[0] == $key, include entire file since there is nothing to search
							pts_client::test_profile_debug_message('No Result Parsing Hint, Including Entire Result Output');
							$line = trim($output);
						}
						pts_client::test_profile_debug_message('Result Line: ' . $line);

						// FALLBACK HELPERS FOR BELOW
						$did_try_colon_fallback = false;

						do
						{
							$try_again = false;
							$r = explode(' ', pts_strings::trim_spaces(str_replace($space_out_chars, ' ', str_replace('=', ' = ', $line))));
							$r_pos = array_search($key, $r);

							if(!empty($before_string))
							{
								// Using ResultBeforeString tag
								$before_this = array_search($before_string, $r);

								if($before_this !== false)
								{
									$test_results[] = $r[($before_this - 1)];
								}
							}
							else if(!empty($after_string))
							{
								// Using ResultBeforeString tag
								$after_this = array_search($after_string, $r);

								if($after_this !== false)
								{
									$after_this++;
									for($f = $after_this; $f < count($r); $f++)
									{
										if(in_array($r[$f], array(':', ',', '-', '=')))
										{
											continue;
										}

										$test_results[] = $r[$f];
										break;
									}
								}
							}
							else if(isset($r[$template_r_pos]))
							{
								$test_results[] = $r[$template_r_pos];
							}
							else
							{
								// POSSIBLE FALLBACKS TO TRY AGAIN

								if(!$did_try_colon_fallback && strpos($line, ':') !== false)
								{
									$line = str_replace(':', ': ', $line);
									$did_try_colon_fallback = true;
									$try_again = true;
								}
							}
						}
						while($try_again);
					}
					while($is_multi_match && count($test_results) != $count && !empty($output));
				}

				foreach($test_results as $x => &$test_result)
				{
					if($strip_from_result != null)
					{
						$test_result = str_replace($strip_from_result, null, $test_result);
					}
					if($strip_result_postfix != null && substr($test_result, 0 - strlen($strip_result_postfix)) == $strip_result_postfix)
					{
						$test_result = substr($test_result, 0, 0 - strlen($strip_result_postfix));
					}

					// Expand validity checking here
					if($is_numeric_check == true && is_numeric($test_result) == false)
					{
						// E.g. if output time as 06:12.32 (as in blender)
						if(substr_count($test_result, ':') == 1 && substr_count($test_result, '.') == 1 && strpos($test_result, '.') > strpos($test_result, ':'))
						{
							$minutes = substr($test_result, 0, strpos($test_result, ':'));
							$seconds = ' ' . substr($test_result, strpos($test_result, ':') + 1);
							$test_result = ($minutes * 60) + $seconds;
						}
					}
					if($is_numeric_check == true && is_numeric($test_result) == false)
					{
						unset($test_results[$x]);
						continue;
					}

					if($divide_by != null && is_numeric($divide_by) && $divide_by != 0)
					{
						$test_result = $test_result / $divide_by;
					}
					if($multiply_by != null && is_numeric($multiply_by) && $multiply_by != 0)
					{
						$test_result = $test_result * $multiply_by;
					}
				}

				if(empty($test_results))
				{
					continue;
				}

				switch($multi_match)
				{
					case 'REPORT_ALL':
						$test_result = implode(',', $test_results);
						break;
					case 'AVERAGE':
					default:
						if($is_numeric_check)
							$test_result = array_sum($test_results) / count($test_results);
						break;
				}

				if($test_result != false)
				{
					if($scale != null)
					{
						$test_run_request->test_profile->set_result_scale($scale);
					}
					if($proportion != null)
					{
						$test_run_request->test_profile->set_result_proportion($proportion);
					}
					if($precision != null)
					{
						$test_run_request->set_result_precision($precision);
					}

					break;
				}
			}
		}
		return $test_result;
	}
}

?>
