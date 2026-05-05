[This program is a universal Turing machine program, written in an aberrant
brainfuck dialect where the array consists of only five cells but each cell
has an unlimited range. Thus it shows that this dialect is Turing-complete.

See http://www.hevanet.com/cristofd/brainfuck/utm.b for a description of the
Turing machine used, which is due to Yurii Rogozhin.

Except for the i/o code, this program is isomorphic to the Universal Register
Machine (URM) program at http://www.hevanet.com/cristofd/brainfuck/urmutm.txt
which includes some explanatory comments.

As input, the program expects first the left half of the tape, beginning with
the cells farthest from the head, with the symbols 0 1 b < > c represented by
'0' '1' '2' '3' '4' '5'; then a linefeed, then the right half represented the
same way, with the current cell added at the end, then another linefeed. The
output is the right half of the tape, beginning with the cells nearest to the
head, and not including the current cell.

WARNING: It's inherent in the storage method that not only cell values, but
also running times, grow exponentially with the tape length. Thus even trivial
examples will take an insanely long time to run and you shouldn't bother.

Daniel B Cristofani (cristofdathevanetdotcom)
http://www.hevanet.com/cristofd/brainfuck/
]

>,----------[<[>++++++<-]++++++[>------<-]>--[<+>-],----------]>
>,----------[>[<++++++>-]++++++[<------>-]<--[>+<-],----------]<+[
    -[>++++++<-]<<[>++<-]>[<+++>-]
    >>>[<+>-[<+>-[<+>-[<+>-[<+>-[<-----<+>>-[<<<+>>>-]]]]]]<<<[>>>+<<<-]>>>]
    <<[>>+<<-]+<+<+++>>>
      [-
        [<<-<+>>>-
          [<<<---->>>-
            [<<+<++>>>-
              [<+++<-<-->>>-
                [<--<+<+>>>-
                  [<<-<->>>-
                    [<+<+<++++>>>-
                      [<->-
                        [<<-<->>>-
                          [<<<->>>-
                            [<-<<+++>>>-
                              [<++<<---->>>-
                                [<+<<++>>>-
                                  [<[-]>-
                                    [<+++<<->>>-
                                      [<--<<->>>-
                                        [<+<+<++++>>>-
                                          [<++<-<----->>>-
                                            [<--<+<+++++>>>-
                                              [<++<-<-->>>--
                                                [<<<->>>-
    ]]]]]]]]]]]]]]]]]]]]]]<<[
        ->>>[<<<++++++>>>-]<<<<[
            >+<-[>+<-[>+<-[>+<-[>+<-[>----->>+<<<-[>>>>+<<<<-]]]]]]
            >>>>[<<<<+>>>>-]<<<<
        ]>[>>>++++++<<<-]>>[
            >+<-[>+<-[>+<-[>+<-[>+<-[>-----<<<<+>>>-[<<+>>-]]]]]]
            <<[>>+<<-]>>
        ]<<
    ]>
]>>[
    [<+>-[<+>-[<+>-[<+>-[<+>-[<-----<+>>-[<<<+>>>-]]]]]]<<<[>>>+<<<-]>>>]
    <<[>>+<<-]++++++[>++++++++<-]>.[-]>
]++++++++++.
