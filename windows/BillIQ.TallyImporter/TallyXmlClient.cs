using System.Text;
using System.Xml;
using System.Security;

namespace BillIQ.TallyImporter;

internal sealed record TallyCompany(string Name, string Guid);
internal sealed record TallyRecord(string Type, string Key, string Xml);

internal sealed class TallyXmlClient(string endpoint)
{
    private readonly HttpClient _http = new() { BaseAddress = new Uri(endpoint.TrimEnd('/') + "/"), Timeout = TimeSpan.FromSeconds(30) };

    internal async Task<IReadOnlyList<TallyCompany>> CompaniesAsync(CancellationToken cancellationToken)
    {
        var doc = await ExportAsync("List of Companies", null, cancellationToken);
        return Select(doc, "COMPANY").Select(node => new TallyCompany(Text(node, "NAME"), Text(node, "GUID"))).Where(x => !string.IsNullOrWhiteSpace(x.Name)).ToList();
    }

    internal async Task<IReadOnlyList<TallyRecord>> ExportSupportedAsync(DateTime? from, DateTime? to, CancellationToken cancellationToken)
    {
        var variables = new StringBuilder("<SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>");
        if (from.HasValue) variables.Append($"<SVFROMDATE>{from:yyyyMMdd}</SVFROMDATE>");
        if (to.HasValue) variables.Append($"<SVTODATE>{to:yyyyMMdd}</SVTODATE>");
        var reports = new[] { ("Group", "group"), ("Ledger", "ledger"), ("Unit", "unit"), ("Stock Item", "stock_item"), ("Day Book", "voucher") };
        var records = new List<TallyRecord>();
        foreach (var (report, type) in reports)
        {
            var doc = await ExportAsync(report, variables.ToString(), cancellationToken);
            foreach (var node in Select(doc, "GROUP", "LEDGER", "UNIT", "STOCKITEM", "VOUCHER"))
            {
                var key = Text(node, "GUID");
                if (string.IsNullOrWhiteSpace(key)) key = Text(node, "ALTERID");
                if (string.IsNullOrWhiteSpace(key)) key = Text(node, "VOUCHERNUMBER");
                if (string.IsNullOrWhiteSpace(key)) key = Text(node, "NAME");
                if (!string.IsNullOrWhiteSpace(key)) records.Add(new TallyRecord(type, key, node.OuterXml));
            }
        }
        return records.GroupBy(x => x.Type + "\u001f" + x.Key).Select(x => x.First()).ToList();
    }

    private async Task<XmlDocument> ExportAsync(string report, string? variables, CancellationToken cancellationToken)
    {
        var xml = $"<ENVELOPE><HEADER><VERSION>1</VERSION><TALLYREQUEST>Export</TALLYREQUEST><TYPE>Data</TYPE><ID>{SecurityElement.Escape(report)}</ID></HEADER><BODY><DESC><STATICVARIABLES>{variables ?? "<SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>"}</STATICVARIABLES></DESC></BODY></ENVELOPE>";
        using var response = await _http.PostAsync("/", new StringContent(xml, Encoding.UTF8, "application/xml"), cancellationToken);
        var body = await response.Content.ReadAsStringAsync(cancellationToken);
        if (!response.IsSuccessStatusCode) throw new InvalidOperationException($"Tally returned HTTP {(int)response.StatusCode}.");
        var doc = new XmlDocument { XmlResolver = null };
        doc.LoadXml(body);
        var errors = doc.SelectSingleNode("//*[local-name()='LINEERROR']")?.InnerText?.Trim();
        if (!string.IsNullOrWhiteSpace(errors)) throw new InvalidOperationException(errors);
        return doc;
    }

    private static IEnumerable<XmlElement> Select(XmlDocument doc, params string[] names) => doc.SelectNodes("//*")!.OfType<XmlElement>().Where(x => names.Contains(x.LocalName, StringComparer.OrdinalIgnoreCase));
    private static string Text(XmlElement node, string name) => node.SelectSingleNode($".//*[local-name()='{name}']")?.InnerText?.Trim() ?? node.GetAttribute(name).Trim();
}
