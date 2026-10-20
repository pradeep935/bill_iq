using System.Security.Cryptography;
using System.Text;

namespace BillIQ.TallyImporter;

internal static class SecureStore
{
    private static readonly string DirectoryPath = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "BillIQ", "TallyImporter");
    private static readonly string CredentialPath = Path.Combine(DirectoryPath, "credential.bin");
    private static readonly string ResumePath = Path.Combine(DirectoryPath, "resume.json");

    internal static void SaveToken(string apiUrl, string token)
    {
        Directory.CreateDirectory(DirectoryPath);
        var clear = Encoding.UTF8.GetBytes($"{apiUrl.TrimEnd('/')}\n{token}");
        File.WriteAllBytes(CredentialPath, ProtectedData.Protect(clear, null, DataProtectionScope.CurrentUser));
    }

    internal static (string ApiUrl, string Token)? ReadToken()
    {
        if (!File.Exists(CredentialPath)) return null;
        try
        {
            var clear = ProtectedData.Unprotect(File.ReadAllBytes(CredentialPath), null, DataProtectionScope.CurrentUser);
            var parts = Encoding.UTF8.GetString(clear).Split('\n', 2);
            return parts.Length == 2 ? (parts[0], parts[1]) : null;
        }
        catch { return null; }
    }

    internal static void SaveResume(string json) { Directory.CreateDirectory(DirectoryPath); File.WriteAllText(ResumePath, json); }
    internal static string? ReadResume() => File.Exists(ResumePath) ? File.ReadAllText(ResumePath) : null;
    internal static void ClearResume() { if (File.Exists(ResumePath)) File.Delete(ResumePath); }
}
