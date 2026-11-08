from collect import attachment_refs, output_row, split_output

RECORD = {"pluginID": "92373", "port": "0", "protocol": "TCP", "firstSeen": "1762463272", "lastSeen": "1762463279",
          "pluginText": "<plugin_output>\nadministrator\n\nExtended SMB session information attached.\n</plugin_output>"}


def test_split_output_keeps_the_body():
    assert split_output(RECORD["pluginText"]) == "administrator\n\nExtended SMB session information attached."
    assert split_output("") == ""


def test_output_row_flags():
    row = output_row(RECORD, 7, "stamp")
    assert (row["host_asset_id"], row["plugin_id"], row["collected_at"]) == (7, 92373, "stamp")
    assert row["refers_to_attachment"] is True and row["truncated"] is False
    cut = dict(RECORD, pluginText="<plugin_output>abc\n====OUTPUT TRUNCATED (7933)====</plugin_output>")
    assert output_row(cut, 7, "stamp")["truncated"] is True


def test_same_text_gives_the_same_hash():
    assert output_row(RECORD, 1, "a")["output_hash"] == output_row(RECORD, 2, "b")["output_hash"]


def test_attachment_refs():
    assert attachment_refs("") == [] and attachment_refs(None) == []
    assert attachment_refs('[{"id": 5, "name": "report.csv", "type": "text/csv"}]') == [{"id": "5", "name": "report.csv", "type": "text/csv"}]
    assert attachment_refs("12,13") == [{"id": "12", "name": "12"}, {"id": "13", "name": "13"}]
