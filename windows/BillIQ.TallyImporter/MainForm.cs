using System.Text.Json;

namespace BillIQ.TallyImporter;

internal sealed class MainForm : Form
{
    private readonly TextBox _billiqUrl = new() { Text = "https://billiq.rpaywin.com", Width = 360 };
    private readonly TextBox _pairingCode = new() { Width = 220, CharacterCasing = CharacterCasing.Upper };
    private readonly ComboBox _company = new() { Width = 360, DropDownStyle = ComboBoxStyle.DropDownList };
    private readonly ComboBox _mode = new() { Width = 170, DropDownStyle = ComboBoxStyle.DropDownList };
    private readonly DateTimePicker _from = new() { Width = 170, Format = DateTimePickerFormat.Short, ShowCheckBox = true };
    private readonly DateTimePicker _to = new() { Width = 170, Format = DateTimePickerFormat.Short, ShowCheckBox = true };
    private readonly Label _status = new() { AutoSize = true, MaximumSize = new Size(650, 0), Text = "Ready. Tally stays read-only." };
    private readonly ProgressBar _progress = new() { Width = 650, Height = 22 };
    private readonly Button _detect = new() { Text = "Detect TallyPrime", AutoSize = true };
    private readonly Button _pair = new() { Text = "Pair with BillIQ", AutoSize = true };
    private readonly Button _start = new() { Text = "Start Import", AutoSize = true, Enabled = false };
    private string? _tallyEndpoint;
    private string? _token;
    private CancellationTokenSource? _cts;

    internal MainForm()
    {
        Text = "BillIQ Tally Data Migration Tool"; Width = 760; Height = 510; StartPosition = FormStartPosition.CenterScreen;
        Font = new Font("Segoe UI", 10); BackColor = Color.White; MaximizeBox = false;
        _mode.Items.AddRange(["Full Migration", "Incremental Sync"]); _mode.SelectedIndex = 0;
        var title = new Label { Text = "BillIQ  |  Tally Data Migration", Font = new Font("Segoe UI Semibold", 18), AutoSize = true, ForeColor = Color.FromArgb(36, 87, 214) };
        var note = new Label { Text = "Securely stage Tally data for Super Admin review. This tool never writes to Tally.", AutoSize = true };
        var layout = new TableLayoutPanel { Dock = DockStyle.Fill, Padding = new Padding(32), ColumnCount = 2, AutoScroll = true };
        layout.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 190)); layout.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        layout.Controls.Add(title, 0, 0); layout.SetColumnSpan(title, 2); layout.Controls.Add(note, 0, 1); layout.SetColumnSpan(note, 2);
        Add(layout, "BillIQ HTTPS address", _billiqUrl, 2); Add(layout, "One-time pairing code", _pairingCode, 3); Add(layout, "", _pair, 4);
        Add(layout, "TallyPrime", _detect, 5); Add(layout, "Detected company", _company, 6); Add(layout, "Migration mode", _mode, 7); Add(layout, "From date (optional)", _from, 8); Add(layout, "To date (optional)", _to, 9); Add(layout, "", _start, 10);
        layout.Controls.Add(_progress, 0, 11); layout.SetColumnSpan(_progress, 2); layout.Controls.Add(_status, 0, 12); layout.SetColumnSpan(_status, 2);
        Controls.Add(layout);
        _detect.Click += async (_, _) => await DetectAsync(); _pair.Click += async (_, _) => await PairAsync(); _start.Click += async (_, _) => await ImportAsync();
        var saved = SecureStore.ReadToken(); if (saved is not null) { _billiqUrl.Text = saved.Value.ApiUrl; _token = saved.Value.Token; SetStatus("Saved BillIQ credential is available. Detect TallyPrime to continue."); }
    }

    private static void Add(TableLayoutPanel layout, string label, Control control, int row) { layout.Controls.Add(new Label { Text = label, AutoSize = true, Anchor = AnchorStyles.Left }, 0, row); layout.Controls.Add(control, 1, row); }
    private void SetStatus(string value) { _status.Text = value; }

    private async Task DetectAsync()
    {
        Toggle(false); SetStatus("Looking for local TallyPrime XML…");
        try
        {
            foreach (var port in new[] { 9000, 9001, 9002, 9003, 9004, 9005 })
            {
                try
                {
                    var endpoint = $"http://127.0.0.1:{port}"; var client = new TallyXmlClient(endpoint); var companies = await client.CompaniesAsync(CancellationToken.None);
                    _tallyEndpoint = endpoint; _company.DataSource = companies; _company.DisplayMember = nameof(TallyCompany.Name); _start.Enabled = _token is not null && companies.Count > 0;
                    SetStatus(companies.Count > 0 ? $"TallyPrime found at port {port}. Select the company to migrate." : $"TallyPrime responded at port {port}, but no company is open."); return;
                }
                catch { }
            }
            SetStatus("TallyPrime XML was not found. Open the required company in TallyPrime and enable its local XML/HTTP service. Do not expose it to the internet.");
        }
        finally { Toggle(true); }
    }

    private async Task PairAsync()
    {
        if (string.IsNullOrWhiteSpace(_pairingCode.Text)) { SetStatus("Enter the one-time code shown by a BillIQ Super Admin."); return; }
        Toggle(false); try { var api = new BillIqApi(_billiqUrl.Text); _token = await api.ExchangeAsync(_pairingCode.Text, CancellationToken.None); SecureStore.SaveToken(_billiqUrl.Text, _token); _pairingCode.Clear(); _start.Enabled = _tallyEndpoint is not null && _company.SelectedItem is not null; SetStatus("Paired securely. The credential is protected for this Windows user."); }
        catch (Exception ex) { SetStatus(ex.Message); } finally { Toggle(true); }
    }

    private async Task ImportAsync()
    {
        if (_tallyEndpoint is null || _token is null || _company.SelectedItem is not TallyCompany company) return;
        Toggle(false); _cts = new CancellationTokenSource();
        try
        {
            if (_from.Checked && _to.Checked && _from.Value.Date > _to.Value.Date) throw new InvalidOperationException("The start date cannot be after the end date.");
            var tally = new TallyXmlClient(_tallyEndpoint); SetStatus("Reading supported Tally data…"); var records = await tally.ExportSupportedAsync(_from.Checked ? _from.Value.Date : null, _to.Checked ? _to.Value.Date : null, _cts.Token);
            var batches = records.Chunk(100).ToArray(); _progress.Maximum = Math.Max(1, batches.Length); var api = new BillIqApi(_billiqUrl.Text); var mode = _mode.SelectedIndex == 0 ? "full" : "incremental"; var runId = Guid.NewGuid().ToString("N"); var start = 0;
            var previous = SecureStore.ReadResume();
            if (previous is not null) { try { using var state = JsonDocument.Parse(previous); var root = state.RootElement; if (root.GetProperty("company").GetString() == company.Guid && root.GetProperty("mode").GetString() == mode && root.GetProperty("total_batches").GetInt32() == batches.Length) { runId = root.GetProperty("run_id").GetString() ?? runId; start = root.GetProperty("next_batch").GetInt32(); } } catch { SecureStore.ClearResume(); } }
            for (var i = start; i < batches.Length; i++)
            {
                var payload = new { mode, date_from = _from.Checked ? _from.Value.ToString("yyyy-MM-dd") : null, date_to = _to.Checked ? _to.Value.ToString("yyyy-MM-dd") : null, company_name = company.Name, company_guid = company.Guid, records = batches[i].Select(x => new { type = x.Type, key = x.Key, payload = new { xml = x.Xml } }) };
                var requestId = $"desktop-{runId}-{i}";
                await RetryAsync(() => api.UploadAsync(_token, payload, requestId, _cts.Token));
                SecureStore.SaveResume(JsonSerializer.Serialize(new { company = company.Guid, mode, run_id = runId, next_batch = i + 1, total_batches = batches.Length })); _progress.Value = i + 1; SetStatus($"Uploaded batch {i + 1} of {batches.Length}. You may safely restart; completed batches will resume.");
            }
            SecureStore.ClearResume(); SetStatus($"Migration staged: {records.Count} supported records transferred. Return to BillIQ for mapping and Super Admin approval; nothing has been posted.");
        }
        catch (Exception ex) { SetStatus("Import paused: " + ex.Message + " You can correct the issue and run again safely."); }
        finally { Toggle(true); }
    }

    private static async Task RetryAsync(Func<Task> action) { Exception? last = null; for (var attempt = 1; attempt <= 3; attempt++) { try { await action(); return; } catch (Exception ex) { last = ex; await Task.Delay(TimeSpan.FromSeconds(attempt * 2)); } } throw last!; }
    private void Toggle(bool enabled) { _detect.Enabled = enabled; _pair.Enabled = enabled; _start.Enabled = enabled && _token is not null && _tallyEndpoint is not null && _company.SelectedItem is not null; }
}
