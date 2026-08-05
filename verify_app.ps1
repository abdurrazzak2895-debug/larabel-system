$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$session.UserAgent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36"
$session.Cookies.Add((New-Object System.Net.Cookie("NEXT_LOCALE", "en", "/", "localhost")))
$session.Cookies.Add((New-Object System.Net.Cookie("auth_token", "eyJhbGciOiJIUzI1NiJ9.eyJhdXRoZW50aWNhdGVkIjp0cnVlLCJleHAiOjE3ODgyODc0MTR9.lvcTiZiTV95L-lYPTj213VgEJ_7ONT98AK00iv-SfEQ", "/", "localhost")))
$session.Cookies.Add((New-Object System.Net.Cookie("XSRF-TOKEN", "eyJpdiI6ImMvcENiWFk5TE1oRGdDODhHeWlOYnc9PSIsInZhbHVlIjoid1kvU3dVWFJkK1oxV0NKVWVJZE04eXV2Y29McU5FRHlBSldoSmVUditLVUUyTFlJM1QrQkR6dDcvb0xEOTJtUlNuTmtRaFpiYStJWDNhd2RkUE1vWEZaaDNJTzRMVFY1RlVBam9KaHpCTGlqZU8xVVlXaWJmeTM2MGpvVkk2d24iLCJtYWMiOiI4NzE1NTEzYjc2NDQ3NzFjMGM4M2YxN2VhOGRiMWM2ZWNjYTU4Njg1NWE5ZWQ2NWZmNjFkYzY1NjlhOGJlMDg5IiwidGFnIjoiIn0%3D", "/", "localhost")))
$session.Cookies.Add((New-Object System.Net.Cookie("laravel-session", "eyJpdiI6IkU5ZVU3SmtkOG90U3FydTJ1eHQ2blE9PSIsInZhbHVlIjoiNFpxdVJqLzVtREV2ZkpMSytPYnZPWERFbk4rT1JlUGcxWmIyc2FkcW15ZU1iYlVwdkIyN3Ayakk1TXBpbDltTVl5Ti9ZcG5WYzd5MGVCMnNJL0xmNW9PdW9uS3o0M3pDUDRSSlVRdFdRK25IcWowb01HMFpJa2JEeXRJTFZxUFAiLCJtYWMiOiIyNWMxMWZmM2Y5ZGY5NGQwZWVlOTY2NzhmOGU4ZGE5ZjE1YzBiZWYzNWMyYTFmMDM4MDJhN2E3NDk0OTQ3M2NiIiwidGFnIjoiIn0%3D", "/", "localhost")))
$response = Invoke-WebRequest -UseBasicParsing -Uri "http://localhost:8000/" `
-WebSession $session `
-Headers @{
  "Accept"="text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7"
  "Accept-Encoding"="gzip, deflate, br, zstd"
  "Accept-Language"="en-US,en;q=0.9"
  "Cache-Control"="max-age=0"
  "Sec-Fetch-Dest"="document"
  "Sec-Fetch-Mode"="navigate"
  "Sec-Fetch-Site"="none"
  "Sec-Fetch-User"="?1"
  "Upgrade-Insecure-Requests"="1"
  "sec-ch-ua"="`"Not;A=Brand`";v=`"8`", `"Chromium`";v=`"150`", `"Google Chrome`";v=`"150`""
  "sec-ch-ua-mobile"="?0"
  "sec-ch-ua-platform"="`"Windows`""
}
Write-Output "STATUS: $($response.StatusCode)"
Write-Output "CONTENT-TYPE: $($response.Headers['Content-Type'])"
Write-Output "--- PAGE TITLE ---"
if ($response.Content -match '<title>(.*?)</title>') { Write-Output $matches[1] } else { Write-Output "(no title tag)" }
Write-Output "--- FIRST 1000 CHARS ---"
Write-Output $response.Content.Substring(0, [Math]::Min(1000, $response.Content.Length))
