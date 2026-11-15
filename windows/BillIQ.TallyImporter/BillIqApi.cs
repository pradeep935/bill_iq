using System.Net.Http.Json;
using System.Text.Json;

namespace BillIQ.TallyImporter;

internal sealed class BillIqApi(string baseUrl)
{
    private readonly HttpClient _http = new() { BaseAddress = new Uri(baseUrl.TrimEnd('/') + "/"), Timeout = TimeSpan.FromSeconds(90) };

    internal async Task<string> ExchangeAsync(string pairingCode, CancellationToken cancellationToken)
    {
        using var response = await _http.PostAsJsonAsync("api/tally/connector/pair", new { pairing_code = pairingCode.Trim().ToUpperInvariant() }, cancellationToken);
        var body = await response.Content.ReadAsStringAsync(cancellationToken);
        if (!response.IsSuccessStatusCode) throw new InvalidOperationException(ReadMessage(body, "The one-time pairing code was rejected or expired."));
        using var document = JsonDocument.Parse(body);
        return document.RootElement.GetProperty("connector_token").GetString() ?? throw new InvalidOperationException("BillIQ did not issue a connector credential.");
    }

    internal async Task UploadAsync(string token, object payload, string requestId, CancellationToken cancellationToken)
    {
        using var request = new HttpRequestMessage(HttpMethod.Post, "api/tally/connector/ingest") { Content = JsonContent.Create(payload) };
        request.Headers.Add("X-BillIQ-Connector-Token", token);
        request.Headers.Add("X-Connector-Request-Id", requestId);
        using var response = await _http.SendAsync(request, cancellationToken);
        var body = await response.Content.ReadAsStringAsync(cancellationToken);
        if (!response.IsSuccessStatusCode) throw new InvalidOperationException(ReadMessage(body, "BillIQ rejected this batch."));
    }

    private static string ReadMessage(string body, string fallback)
    {
        try { using var document = JsonDocument.Parse(body); return document.RootElement.TryGetProperty("message", out var message) ? message.GetString() ?? fallback : fallback; }
        catch { return fallback; }
    }
}
