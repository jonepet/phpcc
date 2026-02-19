// expect: 30
#include <vector>

int main() {
    vector<int>* v = new vector<int>();
    v->push_back(10);
    v->push_back(20);
    int result = v->get(0) + v->get(1);
    int s = v->size();
    if (s != 2) {
        return 1;
    }
    delete v;
    return result;
}
