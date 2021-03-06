<?php
/*
** Zabbix
** Copyright (C) 2000-2012 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


/**
 * @package API
 */
abstract class CGraphGeneral extends CZBXAPI {

	const ERROR_TEMPLATE_HOST_MIX = 'templateHostMix';

	/**
	 * Update existing graphs
	 *
	 * @param array $graphs
	 * @return array
	 */
	public function update($graphs) {
		$graphs = zbx_toArray($graphs);
		$graphids = zbx_objectValues($graphs, 'graphid');

		$updateGraphs = $this->get(array(
			'graphids' => $graphids,
			'editable' => true,
			'preservekeys' => true,
			'output' => API_OUTPUT_EXTEND,
			'selectGraphItems' => API_OUTPUT_EXTEND
		));

		foreach ($graphs as $graph) {
			// if missing in $updateGraphs then no permissions
			if (!isset($updateGraphs[$graph['graphid']])) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _('No permissions to referred object or it does not exist!'));
			}
		}

		$this->checkInput($graphs, true);

		foreach ($graphs as $graph) {
			unset($graph['templateid']);

			$graphHosts = API::Host()->get(array(
				'itemids' => zbx_objectValues($graph['gitems'], 'itemid'),
				'output' => API_OUTPUT_EXTEND,
				'editable' => true,
				'templated_hosts' => true
			));

			// mass templated items
			$templatedGraph = false;
			foreach ($graphHosts as $host) {
				if (HOST_STATUS_TEMPLATE == $host['status']) {
					$templatedGraph = $host['hostid'];
					if (count($graphHosts) > 1) {
						self::exception(ZBX_API_ERROR_PARAMETERS, _s($this->getErrorMsg(self::ERROR_TEMPLATE_HOST_MIX), $graph['name']));
					}
					break;
				}
			}


			// check ymin, ymax items
			$this->checkAxisItems($graph, $templatedGraph);

			$this->updateReal($graph, $updateGraphs[$graph['graphid']]);

			// inheritance
			if ($templatedGraph) {
				$this->inherit($graph);
			}
		}

		return array('graphids' => $graphids);
	}

	/**
	 * Create new graphs
	 *
	 * @param array $graphs
	 * @return array
	 */
	public function create($graphs) {
		$graphs = zbx_toArray($graphs);
		$graphids = array();

		$this->checkInput($graphs, false);

		foreach ($graphs as $graph) {
			$graphHosts = API::Host()->get(array(
				'itemids' => zbx_objectValues($graph['gitems'], 'itemid'),
				'output' => API_OUTPUT_EXTEND,
				'editable' => true,
				'templated_hosts' => true
			));

			// check - items from one template
			$templatedGraph = false;
			foreach ($graphHosts as $host) {
				if (HOST_STATUS_TEMPLATE == $host['status']) {
					$templatedGraph = $host['hostid'];
					break;
				}
			}
			if ($templatedGraph && count($graphHosts) > 1) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s($this->getErrorMsg(self::ERROR_TEMPLATE_HOST_MIX), $graph['name']));
			}

			// check ymin, ymax items
			$this->checkAxisItems($graph, $templatedGraph);

			$graphid = $this->createReal($graph);

			if ($templatedGraph) {
				$graph['graphid'] = $graphid;
				$this->inherit($graph);
			}

			$graphids[] = $graphid;
		}

		return array('graphids' => $graphids);
	}


	protected function createReal($graph) {
		$graphids = DB::insert('graphs', array($graph));
		$graphid = reset($graphids);

		foreach ($graph['gitems'] as &$gitem) {
			$gitem['graphid'] = $graphid;
		}
		unset($gitem);

		DB::insert('graphs_items', $graph['gitems']);

		return $graphid;
	}

	/**
	 * Updates the graph if $graph differs from $dbGraph.
	 *
	 * @param $graph
	 * @param $dbGraph
	 *
	 * @return string
	 */
	protected function updateReal($graph, $dbGraph) {
		$dbGitems = $dbGraph['gitems'];
		$dbGitemIds = zbx_objectValues($dbGitems, 'gitemid');

		// update the graph if it's modified
		if ($this->objectModified($graph, $dbGraph)) {
			DB::updateByPk($this->tableName(), $graph['graphid'], $graph);
		}

		// update graph items
		$insertGitems = array();
		$deleteGitemIds = array_combine($dbGitemIds, $dbGitemIds);
		foreach ($graph['gitems'] as $gitem) {
			// updating an existing item
			if (isset($gitem['gitemid'], $dbGitems[$gitem['gitemid']])) {
				if ($this->objectModified($gitem, $dbGitems[$gitem['gitemid']], 'graphs_items')) {
					DB::updateByPk('graphs_items', $gitem['gitemid'], $gitem);
				}

				// remove this graph item from the collection so it won't get deleted
				unset($deleteGitemIds[$gitem['gitemid']]);
			}
			// adding a new item
			else {
				$gitem['graphid'] = $graph['graphid'];
				$insertGitems[] = $gitem;
			}
		}

		if ($deleteGitemIds) {
			DB::delete('graphs_items', array('gitemid' => $deleteGitemIds));
		}
		if ($insertGitems) {
			DB::insert('graphs_items', $insertGitems);
		}

		return $graph['graphid'];
	}

	/**
	 * @param array $object
	 * @return bool
	 */
	public function exists($object) {
		$options = array(
			'filter' => array('flags' => null),
			'output' => API_OUTPUT_SHORTEN,
			'nopermissions' => true,
			'limit' => 1
		);
		if (isset($object['name'])) {
			$options['filter']['name'] = $object['name'];
		}
		if (isset($object['host'])) {
			$options['filter']['host'] = $object['host'];
		}
		if (isset($object['hostids'])) {
			$options['hostids'] = zbx_toArray($object['hostids']);
		}

		if (isset($object['node'])) {
			$options['nodeids'] = getNodeIdByNodeName($object['node']);
		}
		elseif (isset($object['nodeids'])) {
			$options['nodeids'] = $object['nodeids'];
		}

		$objs = $this->get($options);

		return !empty($objs);
	}

	/**
	 * Get graphid by graph name
	 *
	 * params: hostids, name
	 *
	 * @param array $graphData
	 * @return string|boolean
	 */
	public function getObjects($graphData) {
		$options = array(
			'filter' => $graphData,
			'output' => API_OUTPUT_EXTEND
		);
		if (isset($graphData['node'])) {
			$options['nodeids'] = getNodeIdByNodeName($graphData['node']);
		}
		elseif (isset($graphData['nodeids'])) {
			$options['nodeids'] = $graphData['nodeids'];
		}
		return $this->get($options);
	}

	protected function checkAxisItems($graph, $tpl = false) {
		$axisItems = array();
		if (isset($graph['ymin_type']) && $graph['ymin_type'] == GRAPH_YAXIS_TYPE_ITEM_VALUE) {
			$axisItems[$graph['ymin_itemid']] = $graph['ymin_itemid'];
		}
		if (isset($graph['ymax_type']) && $graph['ymax_type'] == GRAPH_YAXIS_TYPE_ITEM_VALUE) {
			$axisItems[$graph['ymax_itemid']] = $graph['ymax_itemid'];
		}

		if (!empty($axisItems)) {
			$options = array(
				'itemids' => $axisItems,
				'output' => API_OUTPUT_SHORTEN,
				'countOutput' => 1
			);
			if ($tpl) {
				$options['hostids'] = $tpl;
			}
			else {
				$options['templated'] = false;
			}

			$cntExist = API::Item()->get($options);

			if ($cntExist != count($axisItems)) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect item for axis value.'));
			}
		}

		return true;
	}
}
