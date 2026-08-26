package nntp

import (
	"regexp"
	"strconv"
	"strings"
)

var overviewSubjectPartPattern = regexp.MustCompile(`(?i)(?:\(|\[)\s*(\d{1,5})\s*/\s*(\d{1,5})\s*(?:\)|\])\s*$`)
var overviewSubjectYEncSuffixPattern = regexp.MustCompile(`(?i)\s+yenc\s*$`)

func ParseOverviewSubject(subject string) (string, int, int) {
	cleanSubject := strings.TrimSpace(subject)
	if cleanSubject == "" {
		return "", 1, 1
	}

	match := overviewSubjectPartPattern.FindStringSubmatchIndex(cleanSubject)
	if match == nil {
		return cleanSubject, 1, 1
	}

	partNumber, partErr := strconv.Atoi(cleanSubject[match[2]:match[3]])
	totalParts, totalErr := strconv.Atoi(cleanSubject[match[4]:match[5]])
	if partErr != nil || totalErr != nil || partNumber < 1 || totalParts < 1 || partNumber > totalParts {
		return cleanSubject, 1, 1
	}

	binaryName := strings.TrimSpace(cleanSubject[:match[0]])
	binaryName = overviewSubjectYEncSuffixPattern.ReplaceAllString(binaryName, "")
	binaryName = strings.TrimSpace(strings.Trim(binaryName, `"'`))
	if binaryName == "" {
		binaryName = cleanSubject
		partNumber = 1
		totalParts = 1
	}

	return binaryName, partNumber, totalParts
}
