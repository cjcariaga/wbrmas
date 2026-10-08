using System;
using System.Collections.Generic;
using System.IO;
using System.Net;
using System.Runtime.Serialization;
using System.Runtime.Serialization.Json;
using System.Security.Cryptography;
using System.Text;
using System.Threading;
using libzkfpcsharp;

namespace FingerprintBridge
{
    [DataContract]
    public sealed class TemplateRequest
    {
        [DataMember(Name = "template")]
        public string Template { get; set; }
        [DataMember(Name = "challenge")]
        public string Challenge { get; set; }
    }

    [DataContract]
    public sealed class IdentifyRequest
    {
        [DataMember(Name = "apiUrl")]
        public string ApiUrl { get; set; }
        [DataMember(Name = "csrfToken")]
        public string CsrfToken { get; set; }
        [DataMember(Name = "candidateTimestamp")]
        public long CandidateTimestamp { get; set; }
        [DataMember(Name = "candidateToken")]
        public string CandidateToken { get; set; }
        [DataMember(Name = "challenge")]
        public string Challenge { get; set; }
        [DataMember(Name = "ticket")]
        public string Ticket { get; set; }
    }

    [DataContract]
    public sealed class FingerprintCandidate
    {
        [DataMember(Name = "user_id")]
        public int UserId { get; set; }
        [DataMember(Name = "template")]
        public string Template { get; set; }
    }

    [DataContract]
    public sealed class CandidateResponse
    {
        [DataMember(Name = "success")]
        public bool Success { get; set; }
        [DataMember(Name = "message")]
        public string Message { get; set; }
        [DataMember(Name = "candidates")]
        public List<FingerprintCandidate> Candidates { get; set; }
    }

    [DataContract]
    public sealed class BridgeResponse
    {
        [DataMember(Name = "success")]
        public bool Success { get; set; }
        [DataMember(Name = "message")]
        public string Message { get; set; }
        [DataMember(Name = "template")]
        public string Template { get; set; }
        [DataMember(Name = "score")]
        public int Score { get; set; }
        [DataMember(Name = "signature")]
        public string Signature { get; set; }
        [DataMember(Name = "user_id")]
        public int UserId { get; set; }
    }

    internal static class Program
    {
        private const string Prefix = "http://127.0.0.1:8765/";
        private const int TemplateSize = 2048;
        private const string BridgeSecret = "WBRMAS@ZK9500Bridge!2026#ChangeThisSecret";
        private static readonly object ScannerLock = new object();
        private static Mutex singleInstance;
        private static IntPtr device = IntPtr.Zero;
        private static bool sdkInitialized;
        private static bool stopping;
        private static int frameWidth = 320;
        private static int frameHeight = 480;

        private static void Main()
        {
            Console.WriteLine("ZK9500 Fingerprint Bridge");
            bool created;
            singleInstance = new Mutex(true, "WBRMAS_FingerprintBridge_ZK9500", out created);
            if (!created)
            {
                Console.WriteLine("Another fingerprint bridge instance is already running.");
                return;
            }
            var listener = new HttpListener();
            listener.Prefixes.Add(Prefix);
            try
            {
                listener.Start();
            }
            catch (Exception ex)
            {
                Console.WriteLine("Bridge listener failed: " + ex.Message);
                return;
            }

            AppDomain.CurrentDomain.ProcessExit += delegate { StopScanner(); };
            Console.CancelKeyPress += delegate(object sender, ConsoleCancelEventArgs args)
            {
                args.Cancel = true;
                stopping = true;
                listener.Stop();
                StopScanner();
            };

            var monitor = new Thread(DeviceMonitor) { IsBackground = true };
            monitor.Start();
            Console.WriteLine("Listening on " + Prefix);
            Console.WriteLine("Waiting for ZK9500...");

            while (listener.IsListening && !stopping)
            {
                try
                {
                    var context = listener.GetContext();
                    ThreadPool.QueueUserWorkItem(delegate { Handle(context); });
                }
                catch (HttpListenerException) { break; }
            }
            StopScanner();
        }

        private static void DeviceMonitor()
        {
            while (!stopping)
            {
                lock (ScannerLock)
                {
                    if (device == IntPtr.Zero) TryConnectScanner();
                }
                Thread.Sleep(2000);
            }
        }

        private static bool TryConnectScanner()
        {
            if (!sdkInitialized)
            {
                var ret = zkfp2.Init();
                if (ret != zkfperrdef.ZKFP_ERR_OK)
                {
                    Console.WriteLine("Initialize waiting: " + ret);
                    return false;
                }
                sdkInitialized = true;
            }

            if (zkfp2.GetDeviceCount() <= 0) return false;
            device = zkfp2.OpenDevice(0);
            if (device == IntPtr.Zero)
            {
                Console.WriteLine("OpenDevice waiting...");
                return false;
            }
            ReadFrameSize();
            Console.WriteLine("ZK9500 ready.");
            return true;
        }

        private static void ReadFrameSize()
        {
            var parameter = new byte[4];
            var size = 4;
            if (zkfp2.GetParameters(device, 1, parameter, ref size) == zkfperrdef.ZKFP_ERR_OK)
                zkfp2.ByteArray2Int(parameter, ref frameWidth);
            size = 4;
            if (zkfp2.GetParameters(device, 2, parameter, ref size) == zkfperrdef.ZKFP_ERR_OK)
                zkfp2.ByteArray2Int(parameter, ref frameHeight);
        }

        private static bool EnsureScanner(out string error)
        {
            lock (ScannerLock)
            {
                if (device == IntPtr.Zero && !TryConnectScanner())
                {
                    error = "ZK9500 is not ready. Check the USB connection.";
                    return false;
                }
            }
            error = null;
            return true;
        }

        private static void StopScanner()
        {
            lock (ScannerLock)
            {
                if (device != IntPtr.Zero)
                {
                    zkfp2.CloseDevice(device);
                    device = IntPtr.Zero;
                }
                if (sdkInitialized)
                {
                    zkfp2.Terminate();
                    sdkInitialized = false;
                }
            }
        }

        private static void Handle(HttpListenerContext context)
        {
            try
            {
                var origin = context.Request.Headers["Origin"];
                if (IsLocalOrigin(origin))
                    context.Response.Headers.Add("Access-Control-Allow-Origin", origin);
                context.Response.Headers.Add("Access-Control-Allow-Methods", "GET,POST,OPTIONS");
                context.Response.Headers.Add("Access-Control-Allow-Headers", "Content-Type");
                if (context.Request.HttpMethod == "OPTIONS") { context.Response.StatusCode = 204; return; }

                BridgeResponse response;
                if (context.Request.Url.AbsolutePath == "/health")
                    response = device != IntPtr.Zero
                        ? new BridgeResponse { Success = true, Message = "ZK9500 bridge ready." }
                        : new BridgeResponse { Success = false, Message = "Bridge running; waiting for ZK9500." };
                else if (context.Request.Url.AbsolutePath == "/enroll" && context.Request.HttpMethod == "POST")
                    response = Enroll();
                else if (context.Request.Url.AbsolutePath == "/verify" && context.Request.HttpMethod == "POST")
                    response = Verify(ReadRequest(context.Request));
                else if (context.Request.Url.AbsolutePath == "/identify" && context.Request.HttpMethod == "POST")
                    response = Identify(Deserialize<IdentifyRequest>(ReadBody(context.Request)));
                else
                    response = new BridgeResponse { Success = false, Message = "Unknown bridge endpoint." };

                WriteJson(context, response);
            }
            catch (Exception ex)
            {
                WriteJson(context, new BridgeResponse { Success = false, Message = ex.Message });
            }
            finally
            {
                context.Response.Close();
            }
        }

        private static TemplateRequest ReadRequest(HttpListenerRequest request)
        {
            return Deserialize<TemplateRequest>(ReadBody(request));
        }

        private static string ReadBody(HttpListenerRequest request)
        {
            using (var reader = new StreamReader(request.InputStream, request.ContentEncoding))
                return reader.ReadToEnd();
        }

        private static bool IsLocalOrigin(string origin)
        {
            Uri uri;
            if (String.IsNullOrWhiteSpace(origin) || !Uri.TryCreate(origin, UriKind.Absolute, out uri)) return false;
            return uri.Scheme == Uri.UriSchemeHttp &&
                   (String.Equals(uri.Host, "localhost", StringComparison.OrdinalIgnoreCase) ||
                    String.Equals(uri.Host, "127.0.0.1", StringComparison.OrdinalIgnoreCase));
        }

        private static BridgeResponse Enroll()
        {
            lock (ScannerLock)
            {
                string scannerError;
                if (!EnsureScanner(out scannerError))
                    return new BridgeResponse { Success = false, Message = scannerError };
                var samples = new byte[3][];
                var db = zkfp2.DBInit();
                if (db == IntPtr.Zero)
                    return new BridgeResponse { Success = false, Message = "Fingerprint database init failed." };
                for (var i = 0; i < 3; i++)
                {
                    Console.WriteLine("Enrollment scan " + (i + 1) + "/3");
                    var capture = Capture();
                    if (!capture.Success) return capture.Response;
                    if (i > 0 && zkfp2.DBMatch(db, capture.TemplateBytes, samples[i - 1]) <= 0)
                        return new BridgeResponse { Success = false, Message = "Use the same finger for all three scans." };
                    samples[i] = capture.TemplateBytes;
                    WaitForFingerRemoval();
                }

                var merged = new byte[TemplateSize];
                var mergedSize = 0;
                var ret = zkfp2.DBMerge(db, samples[0], samples[1], samples[2], merged, ref mergedSize);
                if (ret != zkfperrdef.ZKFP_ERR_OK)
                    return new BridgeResponse { Success = false, Message = "Template merge failed: " + ret };
                return new BridgeResponse { Success = true, Message = "Fingerprint enrolled.", Template = zkfp2.BlobToBase64(merged, mergedSize) };
            }
        }

        private static BridgeResponse Verify(TemplateRequest request)
        {
            if (request == null || String.IsNullOrWhiteSpace(request.Template))
                return new BridgeResponse { Success = false, Message = "Missing fingerprint template." };
            lock (ScannerLock)
            {
                string scannerError;
                if (!EnsureScanner(out scannerError))
                    return new BridgeResponse { Success = false, Message = scannerError };
                var stored = zkfp2.Base64ToBlob(request.Template);
                var db = zkfp2.DBInit();
                if (db == IntPtr.Zero) return new BridgeResponse { Success = false, Message = "Fingerprint database init failed." };
                var capture = Capture();
                if (!capture.Success) return capture.Response;
                var score = zkfp2.DBMatch(db, capture.TemplateBytes, stored);
                return score > 0
                    ? new BridgeResponse { Success = true, Message = "Fingerprint verified.", Score = score, Signature = Sign(request.Challenge, score) }
                    : new BridgeResponse { Success = false, Message = "Fingerprint not recognized.", Score = score };
            }
        }

        private static BridgeResponse Identify(IdentifyRequest request)
        {
            if (request == null || String.IsNullOrWhiteSpace(request.ApiUrl) ||
                String.IsNullOrWhiteSpace(request.Challenge) || String.IsNullOrWhiteSpace(request.Ticket) ||
                String.IsNullOrWhiteSpace(request.CandidateToken) || request.CandidateTimestamp <= 0)
                return new BridgeResponse { Success = false, Message = "Missing biometric login challenge." };

            Uri apiUri;
            if (!Uri.TryCreate(request.ApiUrl, UriKind.Absolute, out apiUri) ||
                (apiUri.Scheme != Uri.UriSchemeHttp && apiUri.Scheme != Uri.UriSchemeHttps) ||
                (!String.Equals(apiUri.Host, "localhost", StringComparison.OrdinalIgnoreCase) &&
                 !String.Equals(apiUri.Host, "127.0.0.1", StringComparison.OrdinalIgnoreCase)) ||
                !apiUri.AbsolutePath.EndsWith("/fingerprint_api.php", StringComparison.OrdinalIgnoreCase))
                return new BridgeResponse { Success = false, Message = "Biometric server URL is not a local WBRMAS site." };

            var bridgeProof = Sign("identify-candidates|" + request.Challenge + "|" + request.Ticket + "|" + request.CandidateTimestamp + "|" + request.CandidateToken);
            var form = "challenge=" + Uri.EscapeDataString(request.Challenge) +
                       "&ticket=" + Uri.EscapeDataString(request.Ticket) +
                       "&candidate_timestamp=" + request.CandidateTimestamp +
                       "&candidate_token=" + Uri.EscapeDataString(request.CandidateToken) +
                       "&bridge_proof=" + Uri.EscapeDataString(bridgeProof);
            var candidateUri = new UriBuilder(apiUri) { Query = "action=get_identification_candidates" }.Uri;
            var candidateRequest = (HttpWebRequest)WebRequest.Create(candidateUri);
            candidateRequest.Method = "POST";
            candidateRequest.ContentType = "application/x-www-form-urlencoded";
            candidateRequest.Timeout = 15000;
            var formBytes = Encoding.UTF8.GetBytes(form);
            candidateRequest.ContentLength = formBytes.Length;
            using (var requestStream = candidateRequest.GetRequestStream()) requestStream.Write(formBytes, 0, formBytes.Length);

            CandidateResponse candidateResponse;
            using (var response = (HttpWebResponse)candidateRequest.GetResponse())
            using (var reader = new StreamReader(response.GetResponseStream()))
                candidateResponse = Deserialize<CandidateResponse>(reader.ReadToEnd());
            if (candidateResponse == null || !candidateResponse.Success || candidateResponse.Candidates == null || candidateResponse.Candidates.Count == 0)
                return new BridgeResponse { Success = false, Message = candidateResponse == null ? "No enrolled fingerprints were found." : candidateResponse.Message ?? "No enrolled fingerprints were found." };

            lock (ScannerLock)
            {
                string scannerError;
                if (!EnsureScanner(out scannerError))
                    return new BridgeResponse { Success = false, Message = scannerError };
                var db = zkfp2.DBInit();
                if (db == IntPtr.Zero) return new BridgeResponse { Success = false, Message = "Fingerprint database init failed." };
                var capture = Capture();
                if (!capture.Success) return capture.Response;

                var bestScore = 0;
                var matchedUserId = 0;
                foreach (var candidate in candidateResponse.Candidates)
                {
                    if (candidate == null || candidate.UserId <= 0 || String.IsNullOrWhiteSpace(candidate.Template)) continue;
                    var stored = zkfp2.Base64ToBlob(candidate.Template);
                    var score = zkfp2.DBMatch(db, capture.TemplateBytes, stored);
                    if (score > bestScore)
                    {
                        bestScore = score;
                        matchedUserId = candidate.UserId;
                    }
                }
                if (matchedUserId <= 0)
                    return new BridgeResponse { Success = false, Message = "Fingerprint not recognized. Use username and password or try again." };
                return new BridgeResponse
                {
                    Success = true,
                    Message = "Fingerprint identified.",
                    UserId = matchedUserId,
                    Score = bestScore,
                    Signature = Sign(request.Challenge + "|" + matchedUserId + "|" + bestScore + "|identify")
                };
            }
        }

        private static string Sign(string challenge, int score)
        {
            return Sign((challenge ?? "") + "|" + score);
        }

        private static string Sign(string data)
        {
            using (var hmac = new HMACSHA256(Encoding.UTF8.GetBytes(BridgeSecret)))
                return BitConverter.ToString(hmac.ComputeHash(Encoding.UTF8.GetBytes(data ?? ""))).Replace("-", "").ToLowerInvariant();
        }

        private sealed class CaptureResult
        {
            public bool Success;
            public string Error;
            public byte[] TemplateBytes;
            public int Size;
            public BridgeResponse Response { get { return new BridgeResponse { Success = Success, Message = Error }; } }
        }

        private static CaptureResult Capture()
        {
            var image = new byte[frameWidth * frameHeight];
            var template = new byte[TemplateSize];
            var size = TemplateSize;
            var deadline = DateTime.UtcNow.AddSeconds(30);
            while (DateTime.UtcNow < deadline)
            {
                size = TemplateSize;
                int ret;
                try
                {
                    ret = zkfp2.AcquireFingerprint(device, image, template, ref size);
                }
                catch
                {
                    RecoverScanner();
                    return new CaptureResult { Success = false, Error = "ZK9500 connection was interrupted. Please try again." };
                }
                if (ret == zkfperrdef.ZKFP_ERR_OK)
                {
                    return new CaptureResult { Success = true, TemplateBytes = (byte[])template.Clone(), Size = size };
                }
                Thread.Sleep(100);
            }
            return new CaptureResult { Success = false, Error = "Fingerprint scan timed out." };
        }

        private static void RecoverScanner()
        {
            if (device != IntPtr.Zero)
            {
                try { zkfp2.CloseDevice(device); } catch { }
                device = IntPtr.Zero;
            }
        }

        private static void WaitForFingerRemoval()
        {
            var image = new byte[frameWidth * frameHeight];
            var template = new byte[TemplateSize];
            var size = TemplateSize;
            for (var i = 0; i < 20; i++)
            {
                if (zkfp2.AcquireFingerprint(device, image, template, ref size) != zkfperrdef.ZKFP_ERR_OK) return;
                Thread.Sleep(100);
                size = TemplateSize;
            }
        }

        private static T Deserialize<T>(string json)
        {
            using (var stream = new MemoryStream(Encoding.UTF8.GetBytes(json)))
                return (T)new DataContractJsonSerializer(typeof(T)).ReadObject(stream);
        }

        private static void WriteJson(HttpListenerContext context, BridgeResponse response)
        {
            var serializer = new DataContractJsonSerializer(typeof(BridgeResponse));
            using (var stream = new MemoryStream())
            {
                serializer.WriteObject(stream, response);
                var bytes = stream.ToArray();
                context.Response.ContentType = "application/json";
                context.Response.ContentEncoding = Encoding.UTF8;
                context.Response.ContentLength64 = bytes.Length;
                context.Response.OutputStream.Write(bytes, 0, bytes.Length);
            }
        }
    }
}
