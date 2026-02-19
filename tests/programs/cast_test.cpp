// expect: 65
// toolchain: true
int main() {
    int x = 65;
    char c = (char)x;
    int y = (int)c;
    return y;
}
