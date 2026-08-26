package nntp

import (
	"bufio"
	"context"
	"crypto/tls"
	"fmt"
	"net"
	"net/textproto"
	"strconv"
	"strings"
	"time"
)

type GroupStat struct {
	Count int64
	Low   int64
	High  int64
}

type ProbeReport struct {
	Groups      int              `json:"groups"`
	Successful  int              `json:"successful"`
	Failed      int              `json:"failed"`
	TotalCount  int64            `json:"total_count"`
	LowestLow   int64            `json:"lowest_low,omitempty"`
	HighestHigh int64            `json:"highest_high,omitempty"`
	Stats       []ProbeGroupStat `json:"stats,omitempty"`
}

type ProbeGroupStat struct {
	Count int64 `json:"count"`
	Low   int64 `json:"low"`
	High  int64 `json:"high"`
}

type OverviewRange struct {
	Group string
	Start int64
	End   int64
}

type OverviewSampleReport struct {
	Ranges              int                 `json:"ranges"`
	Requested           int                 `json:"requested"`
	Received            int                 `json:"received"`
	Empty               int                 `json:"empty"`
	Parsed              int                 `json:"parsed"`
	Malformed           int                 `json:"malformed"`
	Bytes               int                 `json:"bytes"`
	Lines               int                 `json:"lines"`
	HeaderCandidates    int                 `json:"header_candidates"`
	PartCandidates      int                 `json:"part_candidates"`
	UniqueMessageIDs    int                 `json:"unique_message_ids"`
	DuplicateMessageIDs int                 `json:"duplicate_message_ids"`
	Failed              int                 `json:"failed"`
	Candidates          []OverviewCandidate `json:"-"`
}

type OverviewStats struct {
	Rows                int
	Parsed              int
	Malformed           int
	Bytes               int
	Lines               int
	HeaderCandidates    int
	PartCandidates      int
	UniqueMessageIDs    int
	DuplicateMessageIDs int
	Candidates          []OverviewCandidate
}

type OverviewCandidate struct {
	Group      string `json:"-"`
	Article    int64  `json:"-"`
	Subject    string `json:"-"`
	BinaryName string `json:"-"`
	PartNumber int    `json:"-"`
	TotalParts int    `json:"-"`
	MessageID  string `json:"-"`
	Bytes      int    `json:"-"`
	Lines      int    `json:"-"`
}

var errOverviewUnsupported = fmt.Errorf("NNTP overview command unsupported")

func ProbeGroups(ctx context.Context, config Config, groups []string) (ProbeReport, error) {
	report := ProbeReport{Groups: len(groups)}
	if len(groups) == 0 {
		return report, nil
	}
	if err := config.Validate(); err != nil {
		return report, err
	}

	client, err := Dial(ctx, config)
	if err != nil {
		return report, err
	}
	defer client.Close()

	for _, group := range uniqueGroups(groups) {
		stat, err := client.Group(group)
		if err != nil {
			report.Failed++
			return report, fmt.Errorf("nntp group probe failed for %d of %d group(s)", report.Failed, len(groups))
		}
		report.Successful++
		report.TotalCount += stat.Count
		if report.LowestLow == 0 || stat.Low < report.LowestLow {
			report.LowestLow = stat.Low
		}
		if stat.High > report.HighestHigh {
			report.HighestHigh = stat.High
		}
		report.Stats = append(report.Stats, ProbeGroupStat{
			Count: stat.Count,
			Low:   stat.Low,
			High:  stat.High,
		})
	}

	return report, nil
}

func SampleOverview(ctx context.Context, config Config, ranges []OverviewRange, limit int) (OverviewSampleReport, error) {
	report := OverviewSampleReport{Ranges: len(ranges)}
	if len(ranges) == 0 || limit < 1 {
		return report, nil
	}
	if err := config.Validate(); err != nil {
		return report, err
	}

	client, err := Dial(ctx, config)
	if err != nil {
		return report, err
	}
	defer client.Close()

	for _, overviewRange := range ranges {
		if overviewRange.Start < 1 || overviewRange.End < overviewRange.Start {
			report.Failed++
			return report, fmt.Errorf("nntp overview sample failed for %d of %d range(s)", report.Failed, len(ranges))
		}

		end := overviewRange.End
		if maxEnd := overviewRange.Start + int64(limit) - 1; end > maxEnd {
			end = maxEnd
		}
		report.Requested += int(end - overviewRange.Start + 1)

		if _, err := client.Group(overviewRange.Group); err != nil {
			report.Failed++
			return report, fmt.Errorf("nntp overview sample failed for %d of %d range(s)", report.Failed, len(ranges))
		}
		stats, err := client.Overview(overviewRange.Start, end)
		if err != nil {
			report.Failed++
			return report, fmt.Errorf("nntp overview sample failed for %d of %d range(s)", report.Failed, len(ranges))
		}
		if stats.Rows == 0 {
			report.Empty++
		}
		report.Received += stats.Rows
		report.Parsed += stats.Parsed
		report.Malformed += stats.Malformed
		report.Bytes += stats.Bytes
		report.Lines += stats.Lines
		report.HeaderCandidates += stats.HeaderCandidates
		report.PartCandidates += stats.PartCandidates
		report.UniqueMessageIDs += stats.UniqueMessageIDs
		report.DuplicateMessageIDs += stats.DuplicateMessageIDs
		for _, candidate := range stats.Candidates {
			candidate.Group = overviewRange.Group
			report.Candidates = append(report.Candidates, candidate)
		}
	}

	return report, nil
}

type Client struct {
	conn   net.Conn
	reader *textproto.Reader
	writer *textproto.Writer
}

func Dial(ctx context.Context, config Config) (*Client, error) {
	if err := config.Validate(); err != nil {
		return nil, err
	}

	dialer := net.Dialer{Timeout: config.ConnectTimeout}
	conn, err := dialer.DialContext(ctx, "tcp", config.Address())
	if err != nil {
		return nil, fmt.Errorf("connect to NNTP server: %w", err)
	}

	if config.SSL {
		tlsConn := tls.Client(conn, &tls.Config{ServerName: config.Server, MinVersion: tls.VersionTLS12})
		deadline := time.Now().Add(config.ConnectTimeout)
		_ = tlsConn.SetDeadline(deadline)
		if err := tlsConn.HandshakeContext(ctx); err != nil {
			_ = conn.Close()
			return nil, fmt.Errorf("negotiate NNTP TLS: %w", err)
		}
		conn = tlsConn
	}

	_ = conn.SetDeadline(time.Now().Add(config.SocketTimeout))
	client := &Client{
		conn:   conn,
		reader: textproto.NewReader(bufio.NewReader(conn)),
		writer: textproto.NewWriter(bufio.NewWriter(conn)),
	}

	code, _, err := client.readCodeLine()
	if err != nil {
		_ = conn.Close()
		return nil, err
	}
	if code != 200 && code != 201 {
		_ = conn.Close()
		return nil, fmt.Errorf("NNTP server rejected connection")
	}

	if config.Username != "" {
		if err := client.authenticate(config.Username, config.Password); err != nil {
			_ = conn.Close()
			return nil, err
		}
	}

	return client, nil
}

func (c *Client) Group(group string) (GroupStat, error) {
	if strings.TrimSpace(group) == "" || strings.ContainsAny(group, " \t\r\n") {
		return GroupStat{}, fmt.Errorf("invalid NNTP group name")
	}

	if err := c.writer.PrintfLine("GROUP %s", group); err != nil {
		return GroupStat{}, fmt.Errorf("send NNTP GROUP: %w", err)
	}
	code, line, err := c.readCodeLine()
	if err != nil {
		return GroupStat{}, err
	}
	if code != 211 {
		return GroupStat{}, fmt.Errorf("NNTP GROUP failed")
	}

	fields := strings.Fields(line)
	if len(fields) < 4 {
		return GroupStat{}, fmt.Errorf("NNTP GROUP returned malformed status")
	}
	count, err := strconv.ParseInt(fields[1], 10, 64)
	if err != nil {
		return GroupStat{}, fmt.Errorf("parse NNTP GROUP count: %w", err)
	}
	low, err := strconv.ParseInt(fields[2], 10, 64)
	if err != nil {
		return GroupStat{}, fmt.Errorf("parse NNTP GROUP low water mark: %w", err)
	}
	high, err := strconv.ParseInt(fields[3], 10, 64)
	if err != nil {
		return GroupStat{}, fmt.Errorf("parse NNTP GROUP high water mark: %w", err)
	}

	return GroupStat{Count: count, Low: low, High: high}, nil
}

func (c *Client) Overview(start int64, end int64) (OverviewStats, error) {
	if start < 1 || end < start {
		return OverviewStats{}, fmt.Errorf("invalid NNTP overview range")
	}

	stats, err := c.overviewWithCommand("OVER", start, end)
	if err == errOverviewUnsupported {
		return c.overviewWithCommand("XOVER", start, end)
	}

	return stats, err
}

func (c *Client) Close() error {
	if c.conn == nil {
		return nil
	}
	_ = c.writer.PrintfLine("QUIT")
	return c.conn.Close()
}

func (c *Client) authenticate(username string, password string) error {
	if err := c.writer.PrintfLine("AUTHINFO USER %s", username); err != nil {
		return fmt.Errorf("send NNTP username: %w", err)
	}
	code, _, err := c.readCodeLine()
	if err != nil {
		return err
	}
	if code == 281 {
		return nil
	}
	if code != 381 {
		return fmt.Errorf("NNTP authentication rejected username")
	}

	if err := c.writer.PrintfLine("AUTHINFO PASS %s", password); err != nil {
		return fmt.Errorf("send NNTP password: %w", err)
	}
	code, _, err = c.readCodeLine()
	if err != nil {
		return err
	}
	if code != 281 {
		return fmt.Errorf("NNTP authentication rejected password")
	}

	return nil
}

func (c *Client) overviewWithCommand(command string, start int64, end int64) (OverviewStats, error) {
	if err := c.writer.PrintfLine("%s %d-%d", command, start, end); err != nil {
		return OverviewStats{}, fmt.Errorf("send NNTP overview request: %w", err)
	}
	code, _, err := c.readCodeLine()
	if err != nil {
		return OverviewStats{}, err
	}
	if code != 224 {
		if code == 400 || code == 500 || code == 501 {
			return OverviewStats{}, errOverviewUnsupported
		}
		if code == 423 || code == 430 {
			return OverviewStats{}, nil
		}
		return OverviewStats{}, fmt.Errorf("NNTP overview request failed")
	}

	scanner := bufio.NewScanner(c.reader.DotReader())
	stats := OverviewStats{}
	seenMessageIDs := map[string]struct{}{}
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" {
			continue
		}
		stats.Rows++
		row, err := parseOverviewRow(line)
		if err != nil {
			stats.Malformed++
			continue
		}
		stats.Parsed++
		stats.Bytes += row.Bytes
		stats.Lines += row.Lines
		stats.HeaderCandidates++
		stats.PartCandidates++
		binaryName, partNumber, totalParts := ParseOverviewSubject(row.Subject)
		stats.Candidates = append(stats.Candidates, OverviewCandidate{
			Article:    row.Article,
			Subject:    row.Subject,
			BinaryName: binaryName,
			PartNumber: partNumber,
			TotalParts: totalParts,
			MessageID:  row.MessageID,
			Bytes:      row.Bytes,
			Lines:      row.Lines,
		})
		if _, ok := seenMessageIDs[row.MessageID]; ok {
			stats.DuplicateMessageIDs++
		} else {
			seenMessageIDs[row.MessageID] = struct{}{}
			stats.UniqueMessageIDs++
		}
	}
	if err := scanner.Err(); err != nil {
		return OverviewStats{}, fmt.Errorf("read NNTP overview response: %w", err)
	}

	return stats, nil
}

type overviewRow struct {
	Article   int64
	Subject   string
	MessageID string
	Bytes     int
	Lines     int
}

func parseOverviewRow(line string) (overviewRow, error) {
	fields := strings.Split(line, "\t")
	if len(fields) < 8 {
		return overviewRow{}, fmt.Errorf("NNTP overview row has too few fields")
	}

	article, err := strconv.ParseInt(fields[0], 10, 64)
	if err != nil || article < 1 {
		return overviewRow{}, fmt.Errorf("NNTP overview returned malformed article number")
	}
	subject := strings.TrimSpace(fields[1])
	if subject == "" {
		return overviewRow{}, fmt.Errorf("NNTP overview returned malformed subject")
	}
	messageID := strings.TrimSpace(fields[4])
	if messageID == "" || strings.ContainsAny(messageID, " \t\r\n") {
		return overviewRow{}, fmt.Errorf("NNTP overview returned malformed message id")
	}
	bytes, err := strconv.Atoi(fields[6])
	if err != nil || bytes < 0 {
		return overviewRow{}, fmt.Errorf("NNTP overview returned malformed byte count")
	}
	lines, err := strconv.Atoi(fields[7])
	if err != nil || lines < 0 {
		return overviewRow{}, fmt.Errorf("NNTP overview returned malformed line count")
	}

	return overviewRow{Article: article, Subject: subject, MessageID: messageID, Bytes: bytes, Lines: lines}, nil
}

func (c *Client) readCodeLine() (int, string, error) {
	line, err := c.reader.ReadLine()
	if err != nil {
		return 0, "", fmt.Errorf("read NNTP response: %w", err)
	}
	if len(line) < 3 {
		return 0, "", fmt.Errorf("read malformed NNTP response")
	}
	code, err := strconv.Atoi(line[:3])
	if err != nil {
		return 0, "", fmt.Errorf("read malformed NNTP response code")
	}

	return code, line, nil
}

func uniqueGroups(groups []string) []string {
	seen := map[string]struct{}{}
	unique := []string{}
	for _, group := range groups {
		if _, ok := seen[group]; ok {
			continue
		}
		seen[group] = struct{}{}
		unique = append(unique, group)
	}

	return unique
}
