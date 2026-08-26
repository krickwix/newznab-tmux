package main

import (
	"bufio"
	"flag"
	"fmt"
	"log"
	"net"
	"strings"
)

func main() {
	listenAddr := flag.String("listen", ":1119", "listen address")
	flag.Parse()

	listener, err := net.Listen("tcp", *listenAddr)
	if err != nil {
		log.Fatalf("listen: %v", err)
	}
	defer listener.Close()

	log.Printf("fake NNTP listening on %s", listener.Addr())
	for {
		conn, err := listener.Accept()
		if err != nil {
			log.Printf("accept: %v", err)
			continue
		}
		go serve(conn)
	}
}

func serve(conn net.Conn) {
	defer conn.Close()

	reader := bufio.NewReader(conn)
	writer := bufio.NewWriter(conn)
	writeLine(writer, "200 fake nntp ready")

	for {
		line, err := reader.ReadString('\n')
		if err != nil {
			return
		}
		line = strings.TrimSpace(line)

		response := responseFor(line)
		writeLine(writer, response)
		if line == "QUIT" {
			return
		}
	}
}

func responseFor(line string) string {
	switch line {
	case "GROUP alt.binaries.movies":
		return "211 100000 1 100000 alt.binaries.movies"
	case "OVER 1001-1002":
		return "224 overview follows\r\n1001\tMovie.One\tposter@example.test\t17 Jun 2026 10:00:00 +0000\t<1001@example.test>\t\t1234\t45\r\n1002\tMovie.Two\tposter@example.test\t17 Jun 2026 10:01:00 +0000\t<1002@example.test>\t\t1235\t46\r\n."
	case "OVER 11001-11002":
		return "224 overview follows\r\n11001\tMovie.Three\tposter@example.test\t17 Jun 2026 10:02:00 +0000\t<11001@example.test>\t\t1236\t47\r\n11002\tMovie.Four\tposter@example.test\t17 Jun 2026 10:03:00 +0000\t<11002@example.test>\t\t1237\t48\r\n."
	case "OVER 21001-21002":
		return "224 overview follows\r\n21001\tMovie.Five\tposter@example.test\t17 Jun 2026 10:04:00 +0000\t<21001@example.test>\t\t1238\t49\r\n21002\tMovie.Six\tposter@example.test\t17 Jun 2026 10:05:00 +0000\t<21002@example.test>\t\t1239\t50\r\n."
	case "GROUP a.b.multimedia.movies":
		return "211 200000 1 200000 a.b.multimedia.movies"
	case "GROUP a.b.multimedia.vintage-film":
		return "211 200000 2 200000 a.b.multimedia.vintage-film"
	case "OVER 30000-30001":
		return "224 overview follows\r\n30000\tBackfill.One\tposter@example.test\t17 Jun 2026 11:00:00 +0000\t<30000@example.test>\t\t2234\t55\r\n30001\tBackfill.Two\tposter@example.test\t17 Jun 2026 11:01:00 +0000\t<30001@example.test>\t\t2235\t56\r\n."
	case "OVER 2-3":
		return "224 overview follows\r\n2\tVintage.One\tposter@example.test\t17 Jun 2026 11:02:00 +0000\t<2@example.test>\t\t2236\t57\r\n3\tVintage.Two\tposter@example.test\t17 Jun 2026 11:03:00 +0000\t<3@example.test>\t\t2237\t58\r\n."
	case "OVER 10000-10001":
		return "224 overview follows\r\n10000\tBackfill.Three\tposter@example.test\t17 Jun 2026 11:04:00 +0000\t<10000@example.test>\t\t2238\t59\r\n10001\tBackfill.Four\tposter@example.test\t17 Jun 2026 11:05:00 +0000\t<10001@example.test>\t\t2239\t60\r\n."
	case "OVER 1-2":
		return "224 overview follows\r\n1\tBackfill.Five\tposter@example.test\t17 Jun 2026 11:06:00 +0000\t<1@example.test>\t\t2240\t61\r\n2\tBackfill.Six\tposter@example.test\t17 Jun 2026 11:07:00 +0000\t<2b@example.test>\t\t2241\t62\r\n."
	case "QUIT":
		return "205 closing"
	default:
		return fmt.Sprintf("500 unexpected command %q", line)
	}
}

func writeLine(writer *bufio.Writer, response string) {
	_, _ = writer.WriteString(response + "\r\n")
	_ = writer.Flush()
}
