<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9">
    <xsl:output method="html" version="1.0" encoding="UTF-8" indent="yes"/>
    
    <xsl:template match="/">
        <html xmlns="http://www.w3.org/1999/xhtml">
            <head>
                <title>XML Sitemap - ThinkRank SEO</title>
                <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
                <style type="text/css">
                    body {
                        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                        margin: 0;
                        padding: 20px;
                        background-color: #f8f9fa;
                        color: #333;
                        line-height: 1.6;
                    }
                    .header {
                        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                        color: white;
                        padding: 30px;
                        border-radius: 10px;
                        margin-bottom: 30px;
                        text-align: center;
                    }
                    .header h1 {
                        margin: 0;
                        font-size: 2.5em;
                        font-weight: 300;
                    }
                    .header p {
                        margin: 10px 0 0 0;
                        opacity: 0.9;
                        font-size: 1.1em;
                    }
                    .stats {
                        display: flex;
                        justify-content: center;
                        gap: 30px;
                        margin: 20px 0;
                        flex-wrap: wrap;
                    }
                    .stat-item {
                        background: rgba(255,255,255,0.2);
                        padding: 15px 25px;
                        border-radius: 8px;
                        text-align: center;
                    }
                    .stat-number {
                        font-size: 2em;
                        font-weight: bold;
                        display: block;
                    }
                    .stat-label {
                        font-size: 0.9em;
                        opacity: 0.8;
                    }
                    .container {
                        max-width: 1200px;
                        margin: 0 auto;
                        background: white;
                        border-radius: 10px;
                        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                        overflow: hidden;
                    }
                    .table-header {
                        background: #f8f9fa;
                        padding: 20px;
                        border-bottom: 2px solid #e9ecef;
                    }
                    .table-header h2 {
                        margin: 0;
                        color: #495057;
                        font-size: 1.5em;
                    }
                    table {
                        width: 100%;
                        border-collapse: collapse;
                        font-size: 14px;
                    }
                    th {
                        background: #6c757d;
                        color: white;
                        padding: 15px 10px;
                        text-align: left;
                        font-weight: 600;
                        text-transform: uppercase;
                        font-size: 12px;
                        letter-spacing: 0.5px;
                    }
                    td {
                        padding: 12px 10px;
                        border-bottom: 1px solid #e9ecef;
                        vertical-align: top;
                    }
                    tr:hover {
                        background-color: #f8f9fa;
                    }
                    .url-cell {
                        max-width: 400px;
                        word-break: break-all;
                    }
                    .url-cell a {
                        color: #007bff;
                        text-decoration: none;
                        font-weight: 500;
                    }
                    .url-cell a:hover {
                        text-decoration: underline;
                        color: #0056b3;
                    }
                    .priority-high { color: #28a745; font-weight: bold; }
                    .priority-medium { color: #ffc107; font-weight: bold; }
                    .priority-low { color: #6c757d; }
                    .changefreq {
                        background: #e9ecef;
                        padding: 4px 8px;
                        border-radius: 4px;
                        font-size: 12px;
                        text-transform: uppercase;
                        font-weight: 600;
                    }
                    .lastmod {
                        font-family: 'Courier New', monospace;
                        font-size: 12px;
                        color: #6c757d;
                    }
                    .footer {
                        text-align: center;
                        padding: 30px;
                        color: #6c757d;
                        background: #f8f9fa;
                        margin-top: 30px;
                        border-radius: 10px;
                    }
                    .footer a {
                        color: #007bff;
                        text-decoration: none;
                    }
                    .footer a:hover {
                        text-decoration: underline;
                    }
                    @media (max-width: 768px) {
                        body { padding: 10px; }
                        .header { padding: 20px; }
                        .header h1 { font-size: 2em; }
                        .stats { gap: 15px; }
                        .stat-item { padding: 10px 15px; }
                        table { font-size: 12px; }
                        th, td { padding: 8px 6px; }
                        .url-cell { max-width: 200px; }
                    }
                </style>
            </head>
            <body>
                <div class="header">
                    <h1>XML Sitemap</h1>
                    <p>Generated by ThinkRank SEO Plugin</p>
                    <div class="stats">
                        <div class="stat-item">
                            <span class="stat-number"><xsl:value-of select="count(sitemap:urlset/sitemap:url)"/></span>
                            <span class="stat-label">Total URLs</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number">Today</span>
                            <span class="stat-label">Generated</span>
                        </div>
                    </div>
                </div>

                <div class="container">
                    <div class="table-header">
                        <h2>Sitemap URLs</h2>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>URL</th>
                                <th>Last Modified</th>
                                <th>Priority</th>
                                <th>Change Frequency</th>
                            </tr>
                        </thead>
                        <tbody>
                            <xsl:for-each select="sitemap:urlset/sitemap:url">
                                <xsl:sort select="sitemap:priority" order="descending"/>
                                <tr>
                                    <td class="url-cell">
                                        <a href="{sitemap:loc}" target="_blank">
                                            <xsl:value-of select="sitemap:loc"/>
                                        </a>
                                    </td>
                                    <td class="lastmod">
                                        <xsl:value-of select="substring(sitemap:lastmod, 1, 10)"/>
                                    </td>
                                    <td>
                                        <xsl:attribute name="class">
                                            <xsl:choose>
                                                <xsl:when test="sitemap:priority &gt;= 0.8">priority-high</xsl:when>
                                                <xsl:when test="sitemap:priority &gt;= 0.5">priority-medium</xsl:when>
                                                <xsl:otherwise>priority-low</xsl:otherwise>
                                            </xsl:choose>
                                        </xsl:attribute>
                                        <xsl:value-of select="sitemap:priority"/>
                                    </td>
                                    <td>
                                        <span class="changefreq">
                                            <xsl:value-of select="sitemap:changefreq"/>
                                        </span>
                                    </td>
                                </tr>
                            </xsl:for-each>
                        </tbody>
                    </table>
                </div>

                <div class="footer">
                    <p>This XML sitemap is used by search engines to better understand your website structure.</p>
                    <p>Generated by <a href="https://wordpress.org/plugins/thinkrank/" target="_blank">ThinkRank SEO</a></p>
                </div>
            </body>
        </html>
    </xsl:template>
</xsl:stylesheet>
