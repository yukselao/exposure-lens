from filters import build_filters, score_range


def by_name(filters):
    return {f["filterName"]: f["value"] for f in filters}


def test_empty_parameters_add_no_filter():
    assert build_filters({}) == []
    assert build_filters(None) == []


def test_severity_names_and_ids():
    assert by_name(build_filters({"severity": ["critical", "High"]})) == {"severity": "3,4"}
    assert by_name(build_filters({"severity": "4, 3"})) == {"severity": "3,4"}


def test_all_severities_means_no_filter():
    assert build_filters({"severity": ["0", "1", "2", "3", "4"]}) == []


def test_full_score_range_is_not_sent():
    assert score_range(0, 10) is None
    assert score_range(None, None) is None
    assert score_range(9, None) == "9-10"
    assert score_range(None, 6.5) == "0-6.5"
    assert score_range(8, 3) == "3-8"


def test_family_and_repository_use_id_records():
    got = by_name(build_filters({"families": [{"id": "11", "name": "x"}, "12", {"id": "bad"}],
                                 "repositories": [{"id": 6}]}))
    assert got == {"family": [{"id": "11"}, {"id": "12"}], "repository": [{"id": "6"}]}


def test_plugin_ids_ip_name_and_exploit():
    got = by_name(build_filters({"plugin_ids": "20007; 19506, abc", "ip_range": " 10.0.0.1 ; 10.0.1.0/24 ",
                                 "name_contains": " ssl ", "exploit_only": True, "vpr_min": 9}))
    assert got == {"pluginID": "20007,19506", "ip": "10.0.0.1,10.0.1.0/24", "pluginName": "ssl",
                   "exploitAvailable": "true", "vprScore": "9-10"}


def test_filter_shape():
    assert build_filters({"name_contains": "x"}) == [{
        "id": "pluginName", "filterName": "pluginName", "operator": "=", "type": "vuln",
        "isPredefined": True, "value": "x"}]
